<?php

namespace App\Http\Controllers\Notifications;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\SupportRequest;
use App\Http\Resources\Notifications\NoticeResource;
use App\Services\AccountScope;
use App\Services\AuditLogger;
use App\Services\Notifications\NotificationService;
use App\Services\Support\SupportAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function __construct(private SupportAccess $access, private NotificationService $notices, private AccountScope $scope) {}

    public function options(Request $request): JsonResponse
    {
        $this->access->require($request->user(), 'notifications.send');

        return response()->json(['data' => ['users' => $this->access->audience($request->user(), false), 'modes' => $request->user()->membership->account->type->value === 'system' ? ['all', 'agents', 'branches', 'points', 'custom'] : ['all', 'branches', 'points', 'custom']]]);
    }

    private function query(SupportRequest $request): Builder
    {
        $actor = $request->user();
        $q = $this->notices->visible($actor)->with('sender');
        if ($request->input('filter') === 'unread') {
            $q->whereIn('id', $this->notices->unread($actor)->select('id'));
        }
        if ($term = trim($request->input('query', ''))) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $q->where(function ($q) use ($term): void {
                $q->whereRaw("title LIKE ? ESCAPE '!'", [$term])->orWhereRaw("body LIKE ? ESCAPE '!'", [$term]);
            });
        }

        return $q->orderByDesc('id');
    }

    public function index(SupportRequest $request): JsonResponse
    {
        $this->access->require($request->user(), 'notifications.view');
        $page = $this->query($request)->paginate($request->integer('per_page', 20));

        return response()->json(['data' => NoticeResource::collection($page->getCollection())->resolve($request), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function create(SupportRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->notices->send($request->user(), $request->validated(), $request)], 201);
    }

    public function read(SupportRequest $request, int $id): JsonResponse
    {
        $this->notices->read($request->user(), $id);

        return response()->json(['data' => ['read' => true]]);
    }

    public function readPage(SupportRequest $request): JsonResponse
    {
        return response()->json(['data' => ['count' => $this->notices->readPage($request->user(), $request->input('page'))]]);
    }

    public function summary(Request $request): JsonResponse
    {
        $actor = $request->user();
        $this->access->require($actor, 'notifications.view');
        $counts = [];
        $sets = [];
        foreach ($this->notices->unread($actor)->whereNotNull('page')->get(['id', 'page', 'entity_id']) as $n) {
            if ($this->access->allows($actor, $n->page === 'accounts' ? 'account.view' : $n->page.'.view')) {
                $sets[$n->page][(string) ($n->entity_id ?? ('notice-'.$n->id))] = true;
            }
        }
        $scoped = $this->scope->query($actor)->select('id');
        $own = $actor->membership->account_id;
        $pending = [
            'import' => ['import.approve', DB::table('stock_orders')->where('status', 'pending')->whereIn('account_id', $scoped)],
            'prices' => ['prices.approve', DB::table('finance_price_requests')->where('status', 'pending')->whereIn('account_id', $scoped)],
            'exports' => ['exports.approve', DB::table('stock_withdrawals')->where('status', 'pending')->whereIn('account_id', $scoped)],
            'claims' => ['claims.settle', DB::table('stock_claims')->where('purpose', 'damage')->where('status', 'pending')->whereIn('account_id', $scoped)],
            'exceptions' => ['exceptions.approve', DB::table('sales_reprint_requests')->whereIn('status', ['pending', 'escalated'])->where('recipient_id', $own)->whereIn('account_id', $scoped)],
            'wallets' => ['wallets.approve', DB::table('finance_funding_requests')->where('status', 'pending')->where('from_account_id', $own)->whereIn('to_account_id', $scoped)],
        ];
        foreach ($pending as $page => [$permission,$query]) {
            if (in_array($page, ['import', 'claims', 'exports', 'prices'], true) && $actor->membership->account->type !== AccountType::System) {
                continue;
            }
            if (in_array($page, ['wallets', 'prices'], true) && ! $this->scope->query($actor)->whereKey($own)->exists()) {
                continue;
            }
            if ($this->access->allows($actor, $permission) && $this->access->allows($actor, $page.'.view')) {
                foreach ($query->pluck('id') as $id) {
                    $sets[$page][(string) $id] = true;
                }
            }
        }
        if ($this->access->allows($actor, 'support.view')) {
            $unread = $this->access->messages($actor)->whereColumn('ticket_id', 'support_tickets.id')->where('user_id', '<>', $actor->id)->whereRaw('support_messages.id > COALESCE((SELECT last_message_id FROM support_reads WHERE ticket_id = support_tickets.id AND user_id = ?), 0)', [$actor->id]);
            foreach ($this->access->tickets($actor)->where(function ($q) use ($own, $unread): void {
                $q->where(function ($q) use ($own): void {
                    $q->where('recipient_id', $own)->where('status', '<>', 'closed');
                })->orWhereExists($unread->selectRaw('1')->toBase());
            })->pluck('id') as $id) {
                $sets['support'][(string) $id] = true;
            }
        }
        if ($actor->membership->account->type === AccountType::System && $this->access->allows($actor, 'support.view')) {
            foreach (DB::table('company_inquiries')->where('status', 'new')->pluck('id') as $id) {
                $sets['support']['site-'.$id] = true;
            }
        }
        foreach ($sets as $page => $ids) {
            $counts[$page] = count($ids);
        }
        $unread = $this->notices->unread($actor)->count();
        $counts['notifications'] = $unread;

        return response()->json(['data' => ['unread' => $unread, 'sidebar_counts' => $counts]]);
    }

    public function export(SupportRequest $request, AuditLogger $audit): JsonResponse
    {
        $this->access->require($request->user(), 'notifications.export');
        $q = $this->query($request);
        abort_if((clone $q)->count() > 10000, 422, 'ضيّق الفلاتر إلى 10000 إشعار أو أقل.');
        $rows = $q->get();
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['المعرف', 'العنوان', 'النص', 'المرسل', 'التاريخ', 'مقروء'], ',', '"', '');
        foreach ($rows as $notice) {
            $dto = (new NoticeResource($notice))->resolve($request);
            $values = [$dto['id'], $dto['title'], $dto['body'], $dto['sender_name'], $dto['time'], $dto['read'] ? 'نعم' : 'لا'];
            $values = array_map(fn ($v) => preg_match('/^[\s]*[=+@\-]/u', (string) $v) ? "'".$v : (string) $v, $values);
            fputcsv($stream, $values, ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        $audit->record('notifications.export', $request, $request->user(), null, ['count' => $rows->count()]);

        return response()->json(['data' => ['filename' => 'notifications.csv', 'csv' => $csv, 'count' => $rows->count()]]);
    }
}
