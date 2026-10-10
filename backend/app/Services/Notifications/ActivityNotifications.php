<?php

namespace App\Services\Notifications;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Notifications\Notice;
use App\Models\User;
use App\Services\Support\SupportAccess;
use Illuminate\Support\Facades\DB;

class ActivityNotifications
{
    public function __construct(private NotificationService $notices, private SupportAccess $access) {}

    public function publish(int $auditId): void
    {
        DB::transaction(function () use ($auditId): void {
            $audit = DB::table('audit_logs')->where('id', $auditId)->lockForUpdate()->first();
            if (! $audit || ! $audit->user_id || Notice::where('event_key', 'audit:'.$auditId)->exists()) {
                return;
            }
            $actor = User::with('membership.account')->find($audit->user_id);
            if (! $actor) {
                return;
            }
            $details = $audit->details ? json_decode($audit->details, true, 512, JSON_THROW_ON_ERROR) : [];
            $event = $this->event($audit->action, (int) $audit->subject_account_id, $details);
            if (! $event) {
                return;
            }
            [$page,$entity,$type,$title,$accounts,$specificUsers] = $event;
            $users = User::with('membership.account', 'membership.profile')->where('status', 'active')->where('id', '<>', $actor->id)->where(function ($q) use ($accounts, $specificUsers): void {
                $q->whereIn('id', $specificUsers)->orWhereIn('id', DB::table('account_memberships')->whereIn('account_id', $accounts)->where('status', 'active')->select('user_id'));
            })->get();
            $draft = new Notice(['page' => $page, 'entity_id' => $entity, 'entity_type' => $type]);
            $recipients = [];
            foreach ($users as $user) {
                if (! $user->isOperational() || ! $this->access->allows($user, 'account.view') || ! $this->access->allows($user, $page === 'accounts' ? 'account.view' : $page.'.view')) {
                    continue;
                }
                if ($page !== 'notifications' && ! $this->notices->canOpen($user, $draft)) {
                    continue;
                }
                $recipients[] = $user->id;
            }
            $this->notices->create($actor, $title, $actor->name.' · '.$title.' · '.$entity, $recipients, ['page' => $page, 'entity_id' => $entity, 'entity_type' => $type, 'event_key' => 'audit:'.$auditId]);
        }, 3);
    }

    private function event(string $action, int $accountId, array $details): ?array
    {
        $account = Account::find($accountId);
        $system = (int) Account::where('type', AccountType::System->value)->value('id');
        $network = array_values(array_filter([$accountId, $account?->parent_id]));
        $users = [];
        if (in_array($action, ['import.submit', 'import.resubmit', 'import.approve'], true)) {
            $record = DB::table('stock_orders')->find($details['order_id'] ?? 0);
            if (! $record) {
                return null;
            }
            $page = 'import';
            $id = $record->id;
            $type = 'order';
            $users = [$record->creator_id];
            $approved = $action === 'import.approve';
            $accounts = $approved ? [$record->account_id] : [$system];
            $title = $approved ? match ($details['decision'] ?? '') {
                'approve' => 'تم اعتماد الطلبية','return' => 'أعيدت الطلبية للتعديل',default => 'تم رفض الطلبية'
            } : 'طلبية جديدة بانتظار الاعتماد';
        } elseif (str_starts_with($action, 'wallets.')) {
            if (in_array($action, ['wallets.request', 'wallets.approve', 'wallets.cancel'], true)) {
                $record = DB::table('finance_funding_requests')->find($details['request_id'] ?? 0);
                if (! $record) {
                    return null;
                }
                $id = $record->id;
                $type = 'funding_request';
                $accounts = [$action === 'wallets.approve' ? $record->to_account_id : $record->from_account_id];
                $users = [$record->creator_id];
                $title = match ($action) {
                    'wallets.request' => 'طلب تمويل جديد','wallets.cancel' => 'تم إلغاء طلب التمويل',default => ($details['decision'] ?? '') === 'approve' ? 'تم تنفيذ طلب التمويل' : 'تم رفض طلب التمويل'
                };
            } elseif (in_array($action, ['wallets.transfer', 'wallets.deposit'], true)) {
                $id = (int) ($details['transaction_id'] ?? 0);
                $type = 'transaction';
                $accounts = $action === 'wallets.transfer' ? [$accountId] : $network;
                $title = $action === 'wallets.transfer' ? 'تم تحويل الرصيد' : 'تم إيداع رصيد';
            } else {
                return null;
            }
            $page = 'wallets';
        } elseif (str_starts_with($action, 'prices.')) {
            $record = DB::table('finance_price_requests')->find($details['request_id'] ?? 0);
            if (! $record) {
                return null;
            }
            $page = 'prices';
            $id = $record->id;
            $type = 'price_request';
            $accounts = $record->status === 'pending' ? [$system] : [$record->account_id, Account::find($record->account_id)?->parent_id];
            $users = [$record->creator_id];
            $title = match ($action) {
                'prices.propose' => 'اقتراح تعديل أسعار','prices.reverse' => 'تم إلغاء تعديل الأسعار',default => 'تمت مراجعة تعديل الأسعار'
            };
        } elseif (in_array($action, ['exports.request', 'exports.approve'], true)) {
            $record = DB::table('stock_withdrawals')->find($details['withdrawal_id'] ?? 0);
            if (! $record) {
                return null;
            }
            $page = 'exports';
            $id = $record->id;
            $type = 'withdrawal';
            $accounts = $action === 'exports.request' ? [$system] : [$record->account_id];
            $users = [$record->creator_id];
            $title = $action === 'exports.request' ? 'طلب إرجاع مجهّز جديد' : (($details['decision'] ?? '') === 'approve' ? 'تم اعتماد إرجاع المجهّز' : 'تم رفض إرجاع المجهّز');
        } elseif (in_array($action, ['claims.create', 'claims.settle'], true)) {
            $record = DB::table('stock_claims')->find($details['claim_id'] ?? 0);
            if (! $record || $record->purpose !== 'damage') {
                return null;
            }
            $page = 'claims';
            $id = $record->id;
            $type = 'claim';
            $accounts = $action === 'claims.create' ? array_merge($network, [$system]) : $network;
            $users = [$record->creator_id];
            $title = $action === 'claims.create' ? 'مطالبة جديدة' : 'تمت تسوية المطالبة';
        } elseif (in_array($action, ['inventory.quarantine', 'inventory.resume', 'inventory.cancel', 'inventory.restore'], true)) {
            $page = 'inventory';
            $id = (int) ($details['batch_id'] ?? 0);
            $type = 'batch';
            $accounts = $network;
            $title = match ($action) {
                'inventory.quarantine' => 'تم إيقاف بيع الطلبية','inventory.resume' => 'تمت إعادة تفعيل الطلبية','inventory.cancel' => 'تم إلغاء الطلبية',default => 'تم استرجاع الطلبية'
            };
        } elseif (in_array($action, ['sell.create', 'sales.issue', 'sell.result'], true)) {
            $id = (int) ($details['sale_id'] ?? 0);
            $type = 'sale';
            $accounts = $network;
            $failed = $action === 'sell.result' && ($details['status'] ?? '') === 'Print Failed';
            $page = $failed ? 'exceptions' : 'sales';
            $title = $action === 'sell.result' ? ($failed ? 'فشل طباعة يحتاج متابعة' : 'تمت الطباعة') : 'عملية بيع جديدة';
        } elseif (in_array($action, ['sell.reprint', 'exceptions.approve'], true) && isset($details['request_id'])) {
            $record = DB::table('sales_reprint_requests')->find($details['request_id']);
            if (! $record) {
                return null;
            }
            $page = 'exceptions';
            $id = $record->id;
            $type = 'reprint_request';
            $accounts = $action === 'sell.reprint' ? [$record->recipient_id] : [$record->account_id];
            $users = [$record->creator_id];
            $title = $action === 'sell.reprint' ? 'طلب إعادة طباعة' : 'تمت مراجعة طلب إعادة الطباعة';
        } elseif (in_array($action, ['account.create', 'account.update', 'account.status', 'account.permissions', 'staff.create', 'staff.update', 'staff.status'], true) && $account) {
            $page = 'accounts';
            $id = $accountId;
            $type = 'account';
            $accounts = $network;
            $title = str_starts_with($action, 'staff.') ? 'تحديث موظف الحساب' : 'تحديث بيانات الحساب';
        } else {
            return null;
        }
        if (! $id) {
            return null;
        }

        return [$page, (int) $id, $type, $title, array_values(array_filter($accounts)), array_values(array_filter($users))];
    }
}
