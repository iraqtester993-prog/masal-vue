<?php

namespace App\Services\Sales;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Sales\PrintAttempt;
use App\Models\Sales\ReprintRequest;
use App\Models\Sales\Sale;
use App\Models\Stock\StockCard;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceOperations;
use App\Services\ManagementAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrintOperations
{
    public function __construct(private SalesAccess $access, private SalesPolicies $policies, private SalesOperations $sales, private FinanceOperations $operations, private ManagementAuthority $authority, private AuditLogger $audit) {}

    public function start(User $actor, int $id, array $data, Request $request): array
    {
        $this->access->sale($actor, $id, 'sell.print', true);

        return $this->operations->execute($actor, 'sales.print-start', $data + ['sale_id' => $id], function () use ($actor, $id, $data, $request): array {
            $own = $this->access->own($actor, 'sell.print', true);
            $sale = $this->access->sale($actor, $id, 'sell.print', true, true);
            $this->authority->version($sale, (int) $data['version']);
            $context = $this->policies->context($own, $sale->product_id);
            $this->access->device($actor, $own, $request, $context['settings']);
            abort_unless($context['settings']['printing_enabled'], 409, 'الطباعة موقوفة من الإدارة.');
            abort_unless(in_array($sale->status, ['Print Requested', 'Reprint Requested'], true), 409, 'لا يوجد طلب طباعة جاهز.');
            if ($sale->status === 'Reprint Requested') {
                abort_unless(ReprintRequest::whereKey($sale->reprint_request_id)->where('sale_id', $id)->where('status', 'approved')->exists(), 409, 'بانتظار موافقة المسؤول المستلم على إعادة الطباعة.');
            }
            abort_if(Sale::where('account_id', $own->id)->where('print_pending', true)->exists(), 409, 'سجل نتيجة الطباعة المعلقة أولًا؛ النتيجة غير المعروفة ليست فشلًا.');
            abort_if($this->policies->wait($own, $context) > 0, 429, 'انتظر الفاصل المسموح قبل الطباعة التالية.');
            if ($sale->failure_retry) {
                abort_if($sale->failed_retry_count >= $context['policy']['failed_retries'], 422, 'بلغت حد محاولات الفشل؛ اطلب موافقة إعادة الطباعة.');
            }
            $this->policies->checkPrint($sale, $own, $context);
            $kind = $sale->failure_retry ? 'retry' : ($sale->status === 'Reprint Requested' ? 'reprint' : 'initial');
            $attempt = PrintAttempt::create(['sale_id' => $id, 'actor_id' => $actor->id, 'kind' => $kind, 'status' => 'pending', 'started_at' => now(), 'version' => 1]);
            $sale->update(['print_pending' => true, 'print_started_at' => now(), 'failed_retry_count' => $sale->failed_retry_count + ($sale->failure_retry ? 1 : 0), 'failure_retry' => false, 'version' => $sale->version + 1]);
            $this->audit->record('sell.print', $request, $actor, $own->id, ['sale_id' => $id, 'attempt_id' => $attempt->id, 'kind' => $kind]);

            return $this->sales->dto($sale, $request) + ['attempt_id' => $attempt->id];
        });
    }

    public function result(User $actor, int $id, array $data, Request $request): array
    {
        $this->access->sale($actor, $id, 'sell.result', true);

        return $this->operations->execute($actor, 'sales.print-result', $data + ['sale_id' => $id], function () use ($actor, $id, $data, $request): array {
            $own = $this->access->own($actor, 'sell.result', true);
            $sale = $this->access->sale($actor, $id, 'sell.result', true, true);
            $this->authority->version($sale, (int) $data['version']);
            abort_unless($sale->print_pending && in_array($sale->status, ['Print Requested', 'Reprint Requested'], true), 409, 'ابدأ محاولة الطباعة قبل تسجيل نتيجتها.');
            $attempt = PrintAttempt::where('sale_id', $id)->whereKey($data['attempt_id'])->where('status', 'pending')->lockForUpdate()->firstOrFail();
            $success = (bool) $data['success'];
            $context = $this->policies->context($own, $sale->product_id);
            if ($success) {
                $this->policies->checkPrint($sale, $own, $context);
            }
            $status = $success ? ($sale->status === 'Reprint Requested' ? 'Reprinted' : 'Printed') : 'Print Failed';
            $attempt->update(['status' => $success ? 'success' : 'failed', 'reason' => $success ? null : $data['reason'], 'finished_at' => now(), 'version' => $attempt->version + 1]);
            if ($sale->status === 'Reprint Requested') {
                $approval = ReprintRequest::whereKey($sale->reprint_request_id)->where('sale_id', $id)->where('status', 'approved')->lockForUpdate()->firstOrFail();
                $approval->update(['status' => 'used', 'used_at' => now(), 'version' => $approval->version + 1]);
            }
            $values = ['status' => $status, 'print_pending' => false, 'version' => $sale->version + 1, 'failure_reason' => $success ? null : $data['reason']];
            if ($success && ! $sale->first_printed_at) {
                $values['first_printed_at'] = now();
            }
            $sale->update($values);
            StockCard::where('sale_id', $id)->update(['status' => $status, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            $this->audit->record('sell.result', $request, $actor, $own->id, ['sale_id' => $id, 'attempt_id' => $attempt->id, 'status' => $status, 'reason' => $success ? null : $data['reason']]);

            return $this->sales->dto($sale, $request);
        });
    }

    public function retry(User $actor, int $id, array $data, Request $request): array
    {
        $this->access->sale($actor, $id, 'sell.reprint', true);
        $this->access->require($actor, 'sell.print');

        return $this->operations->execute($actor, 'sales.print-retry', $data + ['sale_id' => $id], function () use ($actor, $id, $data, $request): array {
            $own = $this->access->own($actor, 'sell.reprint', true);
            $sale = $this->access->sale($actor, $id, 'sell.reprint', true, true);
            $this->authority->version($sale, (int) $data['version']);
            abort_unless($sale->status === 'Print Failed' && ! $sale->print_pending, 409, 'إعادة المحاولة للطباعة الفاشلة المسجلة فقط.');
            $context = $this->policies->context($own, $sale->product_id);
            $this->access->device($actor, $own, $request, $context['settings']);
            abort_unless($context['settings']['printing_enabled'], 409);
            abort_if($sale->failed_retry_count >= $context['policy']['failed_retries'], 422, 'بلغت الحد؛ اطلب موافقة إعادة الطباعة.');
            abort_if(Sale::where('account_id', $own->id)->where('print_pending', true)->exists(), 409, 'هناك طباعة معلقة.');
            abort_if($this->policies->wait($own, $context) > 0, 429, 'انتظر قبل إعادة المحاولة.');
            $sale->update(['status' => 'Print Requested', 'failure_retry' => true, 'version' => $sale->version + 1]);
            $this->audit->record('sell.reprint', $request, $actor, $own->id, ['sale_id' => $id, 'action' => 'retry-failed']);

            return $this->sales->dto($sale, $request);
        });
    }

    public function requestReprint(User $actor, int $id, array $data, Request $request): array
    {
        $this->access->sale($actor, $id, 'sell.reprint', true);

        return $this->operations->execute($actor, 'sales.reprint-request', $data + ['sale_id' => $id], function () use ($actor, $id, $data, $request): array {
            $own = $this->access->own($actor, 'sell.reprint', true);
            $sale = $this->access->sale($actor, $id, 'sell.reprint', true, true);
            $this->authority->version($sale, (int) $data['version']);
            abort_unless(! $sale->print_pending && in_array($sale->status, ['Printed', 'Print Failed', 'Reprinted'], true), 409, 'سجل نتيجة الطباعة أولًا أو انتظر معالجة الطلب الحالي.');
            $parent = Account::findOrFail($own->parent_id);
            $record = ReprintRequest::create(['sale_id' => $id, 'account_id' => $own->id, 'creator_id' => $actor->id, 'recipient_id' => $parent->id, 'previous_status' => $sale->status, 'reason' => $data['reason'], 'failure_reason' => $sale->failure_reason, 'status' => 'pending', 'history' => [['from' => $own->id, 'to' => $parent->id, 'actor_id' => $actor->id, 'reason' => $data['reason'], 'at' => now()->toISOString()]], 'version' => 1]);
            $sale->update(['status' => 'Reprint Requested', 'reprint_request_id' => $record->id, 'version' => $sale->version + 1]);
            $this->audit->record('sell.reprint', $request, $actor, $own->id, ['sale_id' => $id, 'request_id' => $record->id, 'recipient_id' => $parent->id, 'reason' => $data['reason']]);

            return $this->requestDto($record) + ['sale' => $this->sales->dto($sale, $request)];
        });
    }

    public function requestDto(ReprintRequest $r): array
    {
        return ['id' => $r->id, 'sale_id' => (int) $r->sale_id, 'account_id' => (int) $r->account_id, 'account_name' => $r->account?->name, 'creator_id' => (int) $r->creator_id, 'creator_name' => $r->creator?->name, 'recipient_id' => (int) $r->recipient_id, 'recipient_name' => $r->recipient?->name, 'reviewer_id' => $r->reviewer_id, 'reviewer_name' => $r->reviewer?->name, 'reason' => $r->reason, 'failure_reason' => $r->failure_reason, 'status' => $r->status, 'history' => $r->history, 'version' => $r->version, 'created_at' => $r->created_at->toISOString(), 'reviewed_at' => $r->reviewed_at?->toISOString(), 'used_at' => $r->used_at?->toISOString()];
    }

    public function review(User $actor, int $id, array $data, Request $request, bool $escalate): array
    {
        $this->access->require($actor, 'exceptions.approve');
        $this->access->sales($actor)->whereIn('id', ReprintRequest::whereKey($id)->select('sale_id'))->firstOrFail();

        return $this->operations->execute($actor, $escalate ? 'sales.reprint-escalate' : 'sales.reprint-review', $data + ['request_id' => $id], function () use ($actor, $id, $data, $request, $escalate): array {
            $saleId = (int) ReprintRequest::whereKey($id)->value('sale_id');
            $saleAccount = Account::findOrFail(Sale::whereKey($saleId)->value('account_id'));
            Account::whereIn('id', DB::table('account_closure')->where('descendant_id', $saleAccount->id)->select('ancestor_id'))->where('type', '!=', AccountType::System)->orderBy('id')->lockForUpdate()->get();
            $sale = $this->access->sales($actor)->whereKey($saleId)->lockForUpdate()->firstOrFail();
            $r = ReprintRequest::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authority->version($r, (int) $data['version']);
            abort_unless($r->status === 'pending' && $r->creator_id !== $actor->id && $r->recipient_id === $actor->membership->account_id && $this->access->accounts($actor)->whereKey($r->recipient_id)->exists(), 403, 'القرار من مستخدم مختلف في حساب المسؤول المستلم الحالي فقط.');
            abort_unless($sale->status === 'Reprint Requested' && $sale->reprint_request_id === $r->id && ! $sale->print_pending, 409, 'الطلب لا يخص محاولة الطباعة الحالية.');
            if ($escalate) {
                $recipient = Account::findOrFail($r->recipient_id);
                abort_unless($recipient->parent_id !== null && $recipient->type !== AccountType::System, 422, 'الطلب وصل إلى إدارة النظام.');
                $history = $r->history;
                $history[] = ['from' => $recipient->id, 'to' => $recipient->parent_id, 'actor_id' => $actor->id, 'reason' => $data['reason'], 'at' => now()->toISOString()];
                $r->update(['recipient_id' => $recipient->parent_id, 'history' => $history, 'version' => $r->version + 1]);
            } else {
                $approve = $data['decision'] === 'approve';
                $history = $r->history;
                $history[] = ['decision' => $data['decision'], 'actor_id' => $actor->id, 'reason' => $data['reason'] ?? null, 'at' => now()->toISOString()];
                $r->update(['status' => $approve ? 'approved' : 'rejected', 'reviewer_id' => $actor->id, 'reviewed_at' => now(), 'history' => $history, 'version' => $r->version + 1]);
                $sale->update($approve ? ['reprints' => $sale->reprints + 1, 'version' => $sale->version + 1] : ['status' => $r->previous_status, 'reprint_request_id' => null, 'version' => $sale->version + 1]);
            }
            $this->audit->record('exceptions.approve', $request, $actor, $sale->account_id, ['request_id' => $id, 'action' => $escalate ? 'escalate' : $data['decision'], 'recipient_id' => $r->recipient_id]);

            return $this->requestDto($r);
        });
    }

    public function deliver(User $actor, int $id, array $data, Request $request): array
    {
        $this->access->sale($actor, $id, 'sell.deliver', true);
        $this->access->require($actor, 'data.pin');

        return $this->operations->execute($actor, 'sales.deliver', $data + ['sale_id' => $id], function () use ($actor, $id, $data, $request): array {
            $sale = $this->access->sale($actor, $id, 'sell.deliver', true, true);
            $this->authority->version($sale, (int) $data['version']);
            abort_unless($sale->issued_at && ! $sale->print_pending && ! in_array($sale->status, ['Reserved', 'Cancelled', 'Delivered', 'Reprint Requested'], true), 409, 'لا يمكن التسليم قبل الإصدار أو أثناء طباعة أو طلب معلق.');
            $sale->update(['status' => 'Delivered', 'delivery_channel' => $data['channel'], 'delivery_reference' => $data['reference'], 'version' => $sale->version + 1]);
            StockCard::where('sale_id', $id)->update(['status' => 'Delivered', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            $this->audit->record('sell.deliver', $request, $actor, $sale->account_id, ['sale_id' => $id, 'channel' => $data['channel'], 'reference' => $data['reference']]);

            return $this->sales->dto($sale, $request);
        });
    }
}
