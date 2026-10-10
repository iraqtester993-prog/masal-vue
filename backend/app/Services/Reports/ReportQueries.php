<?php

namespace App\Services\Reports;

use App\Enums\AccountType;
use App\Models\Digital\DigitalConnection;
use App\Models\User;
use App\Services\AccountScope;
use App\Services\Digital\DigitalAccess;
use App\Services\Maps\MapAccess;
use App\Services\Notifications\NotificationService;
use App\Services\Operations\OperationAccess;
use App\Services\Support\SupportAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportQueries
{
    private array $userIds = [];

    public function __construct(private ReportAccess $access, private SupportAccess $support, private NotificationService $notifications, private DigitalAccess $digital, private MapAccess $maps, private OperationAccess $operations, private AccountScope $scope) {}

    public function dates(array $filters): array
    {
        return [empty($filters['from']) ? null : CarbonImmutable::parse($filters['from'], 'Asia/Baghdad')->startOfDay()->utc()->format('Y-m-d H:i:s'), empty($filters['to']) ? null : CarbonImmutable::parse($filters['to'], 'Asia/Baghdad')->endOfDay()->utc()->format('Y-m-d H:i:s')];
    }

    public function period(Builder $query, array $filters, string $column): Builder
    {
        [$from, $to] = $this->dates($filters);
        if ($from !== null) {
            $query->where($column, '>=', $from);
        }
        if ($to !== null) {
            $query->where($column, '<=', $to);
        }

        return $query;
    }

    private function accounts(User $actor, array $filters): \Illuminate\Database\Eloquent\Builder
    {
        return $this->access->accounts($actor, $filters)->select('id');
    }

    private function products(User $actor, array $filters): \Illuminate\Database\Eloquent\Builder
    {
        return $this->access->products($actor, $filters)->select('id');
    }

    private function users(User $actor, array $filters): array
    {
        $key = $actor->id.':'.json_encode(array_intersect_key($filters, array_flip(['agent_id', 'pos_id', 'city'])));

        return $this->userIds[$key] ??= $this->access->users($actor, $filters);
    }

    private function dataset(Builder $query, array $schema, array $fields, array $minor = []): ReportDataset
    {
        $query->selectRaw($fields['_key'].' AS '.$query->getGrammar()->wrap('_key'));
        foreach ($schema['columns'] as &$column) {
            $key = $column['key'];
            $column['source_available'] = ! ($column['protected'] ?? false) && array_key_exists($key, $fields);
            $query->selectRaw(($column['source_available'] ? $fields[$key] : 'NULL').' AS '.$query->getGrammar()->wrap($key));
        }
        unset($column);
        if (isset($fields['_type'])) {
            $query->selectRaw($fields['_type'].' AS '.$query->getGrammar()->wrap('_type'));
        }

        return new ReportDataset($query, $schema, $minor);
    }

    private function json(string $column, string $path): string
    {
        $expression = "JSON_EXTRACT($column, '$.$path')";

        return DB::getDriverName() === 'sqlite' ? $expression : "NULLIF(JSON_UNQUOTE($expression),'null')";
    }

    private function concat(array $parts): string
    {
        return DB::getDriverName() === 'sqlite' ? implode(' || ', $parts) : 'CONCAT('.implode(', ', $parts).')';
    }

    private function each(Builder $query, string $column, string $alias): string
    {
        if (DB::getDriverName() === 'sqlite') {
            $query->crossJoin(DB::raw("json_each($column) AS $alias"));

            return "$alias.key";
        }
        $query->crossJoin(DB::raw("JSON_TABLE($column, '$[*]' COLUMNS(ordinal FOR ORDINALITY, value JSON PATH '$')) AS $alias"));

        return "$alias.ordinal";
    }

    private function active(string $column): string
    {
        return "CASE WHEN $column = 'active' THEN 'مفعل' ELSE 'موقوف' END";
    }

    private function signed(string $column): string
    {
        return 'CAST('.$column.' AS '.(DB::getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED').')';
    }

    public function unavailableReason(string $id): string
    {
        return match ($id) {
            'wallets-legacy' => 'لم تُنقل أرصدة نقدية سابقة موثقة إلى قاعدة البيانات.',
            'prices-policies' => 'لا توجد خدمة لتسجيل قواعد أسعار مؤجلة؛ الأسعار المعتمدة تظهر في تقرير الأسعار.',
            'sales-services' => 'يتطلب سجل الخدمات صلاحية عرض الخدمات الرقمية ومصدر طلباتها.',
            'operations-integrations' => 'يتطلب التقرير صلاحية عرض الربط أو الخدمات الرقمية.',
            'network-archive' => 'عرض الأرشيف يتطلب صلاحية الحسابات المؤرشفة ضمن نطاقك.',
            'network-presence' => 'عرض آخر حضور وموقع يتطلب صلاحية الخريطة ضمن نطاق المستخدمين المسموحين.',
            'users-times' => 'ضوابط أوقات المستخدمين لمالك حساب مدير النظام مع صلاحيات الأمن وضوابطه فقط.',
            'operations-security' => 'يتطلب سجل قرارات الإيقاف حساب النظام وصلاحيات عرض الأمن والتدقيق.',
            'wallets-holds' => 'لا يوجد سجل حجز مستقل لطلبات التمويل؛ حجوزات البيع تظهر في تقرير حجوزات البطاقات.',
            default => 'مصدر هذا التقرير غير متاح للحساب أو لم يُفعّل بعد.',
        };
    }

    public function build(User $actor, array $schema, array $filters): ?ReportDataset
    {
        $id = $schema['id'];
        if (str_starts_with($id, 'sales') || $id === 'inventory-reservations') {
            return $this->sales($actor, $schema, $filters);
        }
        if (str_starts_with($id, 'wallets')) {
            return $this->wallets($actor, $schema, $filters);
        }
        if (str_starts_with($id, 'inventory') || str_starts_with($id, 'claims')) {
            return $this->stock($actor, $schema, $filters);
        }
        if (str_starts_with($id, 'network')) {
            return $this->network($actor, $schema, $filters);
        }
        if (str_starts_with($id, 'prices')) {
            return $this->prices($actor, $schema, $filters);
        }
        if (str_starts_with($id, 'users')) {
            return $this->members($actor, $schema, $filters);
        }
        if (str_starts_with($id, 'support')) {
            return $this->supportReports($actor, $schema, $filters);
        }

        return $this->administration($actor, $schema, $filters);
    }

    private function digitalOrders(User $actor, array $schema, array $filters): ?ReportDataset
    {
        if (! Schema::hasTable('digital_orders') || ! $this->access->allows($actor, 'digital.view')) {
            return null;
        }
        $pos = $actor->membership->account->type === AccountType::Pos;
        foreach ($schema['columns'] as &$column) {
            if ($pos && in_array($column['key'], ['cost', 'profit'], true)) {
                $column['protected'] = true;
                $column['reason'] = 'تكلفة الشركة مخفية عن نقطة البيع؛ لا يوجد سعر شراء مستقل يتيح احتساب ربحها دون كشف هذه التكلفة.';
            }
            if (in_array($column['key'], ['cost', 'profit'], true)) {
                $column['source_note'] = 'التكلفة الموثقة برد الشركة فقط. سعر كتالوج Topup وقت الطلب ليس تكلفة تسوية فعلية؛ الربح لا يحتسب للطلبات غير المكتملة.';
            }
        }
        unset($column);
        $q = DB::table('digital_orders as r')->whereIn('r.id', $this->digital->orders($actor)->select('digital_orders.id'))
            ->whereIn('r.account_id', $this->accounts($actor, $filters))->whereIn('r.product_id', $this->products($actor, $filters))
            ->join('catalog_products as product', 'product.id', '=', 'r.product_id')->join('catalog_providers as provider', 'provider.id', '=', 'product.provider_id')
            ->leftJoinSub($this->access->accounts($actor)->select(['id', 'name']), 'main', 'main.id', '=', 'r.main_account_id');
        if (($filters['currency'] ?? 'IQD') !== 'IQD') {
            $q->whereRaw('1=0');
        }
        $this->period($q, $filters, 'r.created_at');
        $attempts = DB::table('digital_provider_attempts')->select('order_id')->selectRaw('COUNT(*) as count')->groupBy('order_id');
        $q->leftJoinSub($attempts, 'attempts', 'attempts.order_id', '=', 'r.id');
        $cost = "CASE WHEN r.cost_basis='provider_response' THEN r.actual_cost_minor ELSE NULL END";
        $profit = "CASE WHEN r.status='succeeded' AND r.cost_basis='provider_response' THEN ".$this->signed('r.actual_retail_minor').' - '.$this->signed('r.actual_cost_minor').' ELSE NULL END';

        return $this->dataset($q, $schema, ['_key' => 'r.id', 'id' => 'r.id', 'time' => 'r.created_at', 'agent' => 'main.name', 'provider' => 'provider.name', 'service' => "CASE r.type WHEN 'voucher' THEN 'البطاقات' WHEN 'bill' THEN 'دفع الفواتير' ELSE product.name END", 'recipient' => 'r.mobile', 'price' => 'COALESCE(r.actual_retail_minor,r.quoted_retail_minor)', 'cost' => $cost, 'profit' => $profit, 'status' => 'r.status', 'attempts' => 'COALESCE(attempts.count,0)'], ['price', 'cost', 'profit']);
    }

    private function integrations(User $actor, array $schema, array $filters): ?ReportDataset
    {
        if (! Schema::hasTable('digital_connections') || (! $this->access->allows($actor, 'digital.view') && ! $this->access->allows($actor, 'integrations.view'))) {
            return null;
        }
        $connections = $this->digital->connections($actor);
        if ($actor->membership->account->type !== AccountType::System) {
            $visible = (clone $connections)->with(['account', 'company', 'offers.product', 'grants.target'])->get()
                ->filter(fn (DigitalConnection $connection): bool => $connection->account_id === $actor->membership->account_id || $this->digital->offers($connection, $actor->membership->account, true)->isNotEmpty())->pluck('id')->all();
            $connections->whereIn('id', $visible);
        }
        $targets = $this->accounts($actor, $filters);
        $q = DB::table('digital_connections as r')->whereIn('r.id', $connections->select('digital_connections.id'))
            ->whereIn('r.account_id', DB::table('account_closure')->whereIn('descendant_id', $targets)->select('ancestor_id'))
            ->join('accounts as a', 'a.id', '=', 'r.account_id')->leftJoin('catalog_providers as provider', 'provider.id', '=', 'r.provider_id');
        if (! empty($filters['provider_id'])) {
            $q->where('r.provider_id', $filters['provider_id']);
        }
        if (! empty($filters['product_id'])) {
            $q->whereExists(fn ($offers) => $offers->selectRaw('1')->from('digital_offers')->whereColumn('connection_id', 'r.id')->where('product_id', $filters['product_id'])->where('listed', true));
        }
        $hosts = [];
        foreach (['rabiaa', 'topup'] as $provider) {
            $host = parse_url((string) config('digital.'.$provider.'.url'), PHP_URL_HOST);
            $hosts[$provider] = is_string($host) && preg_match('/^[a-z0-9.-]+$/iD', $host) ? DB::connection()->getPdo()->quote($host) : 'NULL';
        }
        foreach ($schema['columns'] as &$column) {
            if ($column['key'] === 'environment') {
                $column['source_note'] = 'اسم مضيف API المضبوط حاليًا؛ لا يمثل تاريخًا سابقًا للربط.';
            }
            if ($column['key'] === 'expiry') {
                $column['reason'] = 'لا توفر عقود الشركات تاريخ انتهاء توكن موثقًا.';
            }
        }
        unset($column);

        return $this->dataset($q, $schema, ['_key' => 'r.id', 'agent' => 'a.name', 'provider' => "COALESCE(provider.name,CASE r.provider WHEN 'rabiaa' THEN 'الرابعة' WHEN 'topup' THEN 'التعبئة المباشرة' ELSE NULL END)", 'environment' => "CASE r.provider WHEN 'rabiaa' THEN {$hosts['rabiaa']} WHEN 'topup' THEN {$hosts['topup']} ELSE NULL END"]);
    }

    private function archivedAccounts(User $actor, array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $q = $this->scope->query($actor, true)->select('accounts.id');
        if (! empty($filters['agent_id'])) {
            $q->whereIn('id', DB::table('account_closure')->where('ancestor_id', $filters['agent_id'])->select('descendant_id'));
        }
        if (! empty($filters['pos_id'])) {
            $q->whereKey($filters['pos_id']);
        }
        if (! empty($filters['city'])) {
            $q->where('city', $filters['city']);
        }

        return $q;
    }

    private function archives(User $actor, array $schema, array $filters): ?ReportDataset
    {
        if (! Schema::hasTable('account_archives') || ! $this->access->allows($actor, 'agents.archiveView')) {
            return null;
        }
        $q = DB::table('account_archives as r')->whereIn('r.id', $this->operations->archives($actor)->select('account_archives.id'))
            ->whereIn('r.account_id', $this->archivedAccounts($actor, $filters))->join('accounts as a', 'a.id', '=', 'r.account_id')->leftJoin('users as u', 'u.id', '=', 'r.actor_id');
        $this->period($q, $filters, 'r.created_at');

        return $this->dataset($q, $schema, ['_key' => 'r.id', 'id' => 'r.id', 'entity' => 'r.account_id', 'name' => 'a.name', 'time' => 'r.created_at', 'user' => 'u.name', 'reason' => 'r.reason']);
    }

    private function operationalAccount(string $alias): string
    {
        return "EXISTS(SELECT 1 FROM account_closure self WHERE self.descendant_id=$alias.id AND self.ancestor_id=$alias.id AND self.depth=0) AND NOT EXISTS(SELECT 1 FROM account_closure c LEFT JOIN accounts ancestor ON ancestor.id=c.ancestor_id WHERE c.descendant_id=$alias.id AND (ancestor.id IS NULL OR ancestor.status<>'active' OR ancestor.archived_at IS NOT NULL OR (ancestor.parent_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM account_closure parent_path WHERE parent_path.descendant_id=$alias.id AND parent_path.ancestor_id=ancestor.parent_id AND parent_path.depth=c.depth+1)) OR (ancestor.parent_id IS NULL AND c.depth<>(SELECT COUNT(*)-1 FROM account_closure count_path WHERE count_path.descendant_id=$alias.id))))";
    }

    private function presence(User $actor, array $schema, array $filters): ?ReportDataset
    {
        if (! Schema::hasTable('user_presences') || ! $this->access->allows($actor, 'map.view')) {
            return null;
        }
        $visible = $this->maps->users($actor)->select('users.id')->reorder();
        $q = DB::table('users as r')->whereIn('r.id', $visible)->join('account_memberships as m', 'm.user_id', '=', 'r.id')->join('accounts as a', 'a.id', '=', 'm.account_id')
            ->leftJoin('permission_profiles as profile', 'profile.id', '=', 'm.permission_profile_id')->whereIn('m.account_id', $this->accounts($actor, $filters));
        $latest = DB::table('user_presences as p')->whereNotExists(function ($newer): void {
            $newer->selectRaw('1')->from('user_presences as newer')->whereColumn('newer.user_id', 'p.user_id')->where(fn ($time) => $time->whereColumn('newer.last_seen_at', '>', 'p.last_seen_at')->orWhere(fn ($equal) => $equal->whereColumn('newer.last_seen_at', 'p.last_seen_at')->whereColumn('newer.id', '>', 'p.id')));
        });
        $q->leftJoinSub($latest, 'presence', 'presence.user_id', '=', 'r.id');
        $now = DB::connection()->getPdo()->quote(now()->format('Y-m-d H:i:s'));
        $cutoff = DB::connection()->getPdo()->quote(now()->subSeconds(120)->format('Y-m-d H:i:s'));
        $online = "r.status='active' AND m.status='active' AND (m.kind<>'employee' OR (profile.status='active' AND profile.account_id=m.account_id)) AND ".$this->operationalAccount('a')." AND EXISTS(SELECT 1 FROM user_presences session_presence WHERE session_presence.user_id=r.id AND session_presence.session_version=r.session_version AND session_presence.connected=1 AND session_presence.last_seen_at>$cutoff AND session_presence.last_seen_at<=$now)";

        return $this->dataset($q, $schema, ['_key' => 'r.id', 'id' => 'r.id', 'name' => 'r.name', 'status' => "CASE WHEN $online THEN 'متصل' ELSE 'غير متصل' END", 'lastSeen' => 'presence.last_seen_at', 'locationTime' => 'presence.location_at', 'lat' => 'presence.latitude', 'lng' => 'presence.longitude', 'accuracy' => 'presence.accuracy']);
    }

    private function timePolicies(User $actor, array $schema, array $filters): ?ReportDataset
    {
        if (! Schema::hasTable('account_time_policies') || $actor->membership->account->type !== AccountType::System || $actor->membership->kind !== 'owner' || ! $this->access->allows($actor, 'security.view') || ! $this->access->allows($actor, 'security.policies')) {
            return null;
        }
        $this->operations->requireSecurity($actor, false, true);
        $q = DB::table('account_time_policies as policy')->join('users as r', 'r.id', '=', 'policy.user_id')->join('account_memberships as m', 'm.user_id', '=', 'r.id')->where('m.kind', 'employee')->whereIn('m.account_id', $this->accounts($actor, $filters));
        $fields = ['_key' => 'r.id', 'id' => 'r.id', 'name' => 'r.name', 'status' => 'CASE WHEN CAST('.$this->json('policy.policy', 'enabled')." AS CHAR) IN ('1','true') THEN 'مفعّل' ELSE 'معطّل' END"];
        foreach (['startAt' => 'dateEnabled', 'endAt' => 'dateEnabled', 'startTime' => 'hoursEnabled', 'endTime' => 'hoursEnabled', 'idleMinutes' => 'idleEnabled', 'sessionMinutes' => 'sessionEnabled'] as $column => $enabled) {
            $fields[$column] = 'CASE WHEN CAST('.$this->json('policy.policy', $enabled)." AS CHAR) IN ('1','true') THEN CAST(".$this->json('policy.policy', $column)." AS CHAR) ELSE 'غير مفعّل' END";
        }

        return $this->dataset($q, $schema, $fields);
    }

    private function securityRows(Builder $query, array $fields): Builder
    {
        foreach (['_key', 'id', 'time', 'user', 'action'] as $key) {
            $query->selectRaw($fields[$key].' AS '.$query->getGrammar()->wrap($key));
        }

        return $query;
    }

    private function securityEvents(User $actor, array $schema, array $filters): ?ReportDataset
    {
        if (! Schema::hasTable('operation_stops') || $actor->membership->account->type !== AccountType::System || ! $this->access->allows($actor, 'security.view') || ! $this->access->allows($actor, 'audit.view')) {
            return null;
        }
        $visible = $this->operations->stops($actor)->select('operation_stops.id');
        $stops = DB::table('operation_stops as r')->whereIn('r.id', $visible);
        if (! empty($filters['agent_id']) || ! empty($filters['pos_id']) || ! empty($filters['city'])) {
            $stops->whereExists(function ($targets) use ($actor, $filters): void {
                $targets->selectRaw('1')->from('accounts as target')->whereIn('target.id', $this->accounts($actor, $filters))->where('target.type', '<>', 'system')
                    ->where(fn ($types) => $types->where('r.scope', 'all')->orWhere(fn ($custom) => $custom->where('r.scope', 'custom')->whereIn('target.id', DB::table('operation_stop_targets')->select('account_id')->whereColumn('stop_id', 'r.id')))->orWhereRaw("target.type=CASE r.scope WHEN 'main' THEN 'main_agent' WHEN 'branch' THEN 'sub_agent' WHEN 'subbranch' THEN 'sub_branch' WHEN 'pos' THEN 'pos' END"))
                    ->where(fn ($roots) => $roots->whereNull('r.scope_roots')->orWhereExists(function ($paths): void {
                        $paths->selectRaw('1')->from('account_closure as path')->join('operation_stop_scope_roots as root', 'root.account_id', '=', 'path.ancestor_id')->whereColumn('root.stop_id', 'r.id')->whereColumn('path.descendant_id', 'target.id')->where(fn ($depth) => $depth->where('r.include_descendants', true)->orWhere('path.depth', 0));
                    }));
            });
        }
        $created = (clone $stops)->leftJoin('users as u', 'u.id', '=', 'r.creator_id');
        $this->period($created, $filters, 'r.created_at');
        $created = $this->securityRows($created, ['_key' => $this->concat(["'stop:'", 'r.id', "':create'"]), 'id' => $this->concat(["'stop:'", 'r.id']), 'time' => 'r.created_at', 'user' => 'u.name', 'action' => $this->concat(["'إيقاف — '", "CASE r.scope WHEN 'all' THEN 'جميع الحسابات' WHEN 'main' THEN 'الوكلاء الرئيسيون' WHEN 'branch' THEN 'الفروع' WHEN 'subbranch' THEN 'الفروع الفرعية' WHEN 'pos' THEN 'نقاط البيع' ELSE 'حسابات محددة' END", "' — '", 'r.reason'])]);
        $resumed = (clone $stops)->leftJoin('users as u', 'u.id', '=', 'r.resumed_by')->whereNotNull('r.resumed_at');
        $this->period($resumed, $filters, 'r.resumed_at');
        $resumed = $this->securityRows($resumed, ['_key' => $this->concat(["'stop:'", 'r.id', "':resume'"]), 'id' => $this->concat(["'stop:'", 'r.id']), 'time' => 'r.resumed_at', 'user' => 'u.name', 'action' => "'رفع الإيقاف'"]);
        $audit = $this->auditBase($actor, $filters)->whereNotIn('r.action', ['security.stop', 'security.resume'])->where(fn ($events) => $events->where('r.action', 'like', 'auth.%')->orWhere('r.action', 'like', 'security.%')->orWhere('r.action', 'like', 'sessions.%'));
        $audit = $this->securityRows($audit, ['_key' => $this->concat(["'audit:'", 'r.id']), 'id' => 'r.id', 'time' => 'r.created_at', 'user' => 'u.name', 'action' => 'r.action']);
        $direct = DB::table('operation_direct_stops as r')->join('accounts as a', 'a.id', '=', 'r.account_id')->whereIn('r.account_id', $this->archivedAccounts($actor, $filters))->whereNotExists(function ($events): void {
            $events->selectRaw('1')->from('audit_logs as direct_audit')->where('direct_audit.action', 'security.direct')->whereColumn('direct_audit.subject_account_id', 'r.account_id')->whereRaw($this->json('direct_audit.details', 'version').'=r.version');
        });
        $this->period($direct, $filters, 'r.updated_at');
        $direct = $this->securityRows($direct, ['_key' => $this->concat(["'direct:'", 'r.account_id']), 'id' => $this->concat(["'direct:'", 'r.account_id']), 'time' => 'r.updated_at', 'user' => 'NULL', 'action' => $this->concat(["'ضوابط مباشرة — '", 'a.name'])]);
        $all = DB::query()->fromSub($created->unionAll($resumed)->unionAll($audit)->unionAll($direct), 'events');

        return $this->dataset($all, $schema, ['_key' => 'events._key', 'id' => 'events.id', 'time' => 'events.time', 'user' => 'events.user', 'action' => 'events.action']);
    }

    private function sales(User $actor, array $schema, array $filters): ?ReportDataset
    {
        if ($schema['id'] === 'sales-services') {
            return $this->digitalOrders($actor, $schema, $filters);
        }
        if (! Schema::hasTable('sales') || ! $this->access->allows($actor, 'sales.view')) {
            return null;
        }
        $id = $schema['id'];
        $q = DB::table('sales as r')->whereIn('r.account_id', $this->accounts($actor, $filters))->whereIn('r.product_id', $this->products($actor, $filters))->where('r.currency', $filters['currency'] ?? 'IQD');
        $q->join('accounts as a', 'a.id', '=', 'r.account_id')->join('catalog_products as p', 'p.id', '=', 'r.product_id')->join('catalog_providers as v', 'v.id', '=', 'r.provider_id')->leftJoin('users as u', 'u.id', '=', 'r.creator_id');
        if ($id === 'inventory-reservations') {
            $q->whereNull('r.issued_at');
            $this->period($q, $filters, 'r.created_at');

            return $this->dataset($q, $schema, ['_key' => 'r.id', 'id' => 'r.id', 'time' => 'r.created_at', 'pos' => 'a.name', 'product' => 'p.name', 'quantity' => 'r.quantity', 'credit' => 'r.credit_minor', 'status' => 'r.status'], ['credit']);
        }
        $q->whereNotNull('r.issued_at');
        $this->period($q, $filters, 'r.issued_at');
        if (! empty($filters['status'])) {
            $q->where('r.status', $filters['status']);
        }
        $pos = $actor->membership->account->type->value === 'pos';
        $total = $pos ? 'r.retail_total_minor' : 'r.total_minor';
        $profit = $pos ? $this->signed('r.retail_total_minor').' - '.$this->signed('r.total_minor') : $this->signed('r.credit_minor').' - '.$this->signed('r.load_cost_minor');
        if (in_array($id, ['sales-products', 'sales-agents', 'sales-pos'], true)) {
            $key = $id === 'sales-products' ? 'r.product_id' : 'r.account_id';
            $name = $id === 'sales-products' ? 'p.name' : 'a.name';
            if ($id === 'sales-agents') {
                $q->join('accounts as main', 'main.id', '=', 'r.main_account_id')->whereIn('main.id', $this->accounts($actor, []));
                $key = 'main.id';
                $name = 'main.name';
            }
            if ($id === 'sales-pos') {
                $q->where('a.type', 'pos');
            }
            $q->groupBy($key, $name);

            return $this->dataset($q, $schema, ['_key' => $key, 'id' => $key, 'name' => $name, 'transactions' => 'COUNT(*)', 'quantity' => 'SUM(r.quantity)', 'sales' => "SUM($total)", 'cost' => 'SUM(r.cost_minor)', 'profit' => "SUM($profit)", 'margin' => "CASE WHEN SUM($total)>0 THEN 100.0*SUM($profit)/SUM($total) ELSE NULL END"], ['sales', 'cost', 'profit']);
        }
        if ($id === 'sales-printing') {
            $q->join('sales_print_attempts as t', 't.sale_id', '=', 'r.id')->leftJoin('users as printer', 'printer.id', '=', 't.actor_id');

            return $this->dataset($q, $schema, ['_key' => 't.id', 'sale' => 'r.id', 'time' => 't.started_at', 'user' => 'printer.name', 'status' => 't.status', 'reason' => 't.reason']);
        }
        if ($id === 'sales-deliveries') {
            $q->whereNotNull('r.delivery_channel');

            return $this->dataset($q, $schema, ['_key' => 'r.id', 'id' => 'r.id', 'tx' => 'r.id', 'time' => 'r.updated_at', 'channel' => 'r.delivery_channel', 'reference' => 'r.delivery_reference', 'user' => 'u.name']);
        }

        return $this->dataset($q, $schema, ['_key' => 'r.id', 'id' => 'r.id', 'time' => 'r.issued_at', 'agent' => "CASE WHEN a.type<>'pos' THEN a.name ELSE NULL END", 'pos' => "CASE WHEN a.type='pos' THEN a.name ELSE NULL END", 'product' => 'p.name', 'provider' => 'v.name', 'quantity' => 'r.quantity', 'price' => $pos ? 'r.retail_price_minor' : 'r.price_minor', 'sales' => $total, 'cost' => 'r.cost_minor', 'profit' => $profit, 'margin' => "CASE WHEN $total>0 THEN 100.0*($profit)/$total ELSE NULL END", 'status' => 'r.status', 'reprints' => 'r.reprints'], ['price', 'sales', 'cost', 'profit']);
    }

    private function wallets(User $actor, array $schema, array $filters): ?ReportDataset
    {
        $id = $schema['id'];
        if (in_array($id, ['wallets-legacy', 'wallets-holds'], true) || ! Schema::hasTable('finance_wallets') || ! $this->access->allows($actor, 'wallets.view')) {
            return null;
        }
        $scope = $this->accounts($actor, $filters);
        $currency = $filters['currency'] ?? 'IQD';
        if ($id === 'wallets') {
            [$from,$to] = $this->dates($filters);
            $q = DB::table('finance_wallets as r')->join('accounts as a', 'a.id', '=', 'r.account_id')->where('r.kind', 'account')->whereIn('r.account_id', $scope)->where('r.currency', $currency);
            $entries = DB::table('finance_entries as e')->join('finance_transactions as t', 't.id', '=', 'e.transaction_id')->where('t.posted', true)->whereColumn('e.wallet_id', 'r.id');
            $opening = (clone $entries)->selectRaw('COALESCE(SUM(e.amount_minor),0)');
            if ($from === null) {
                $opening->whereRaw('1=0');
            } else {
                $opening->where('e.created_at', '<', $from);
            }
            $credits = $this->period((clone $entries)->where('e.amount_minor', '>', 0), $filters, 'e.created_at')->selectRaw('COALESCE(SUM(e.amount_minor),0)');
            $debits = $this->period((clone $entries)->where('e.amount_minor', '<', 0), $filters, 'e.created_at')->selectRaw('-COALESCE(SUM(e.amount_minor),0)');
            $closing = (clone $entries)->selectRaw('COALESCE(SUM(e.amount_minor),0)');
            if ($to !== null) {
                $closing->where('e.created_at', '<=', $to);
            }
            $q->selectSub($opening, '_opening')->selectSub($credits, '_credits')->selectSub($debits, '_debits')->selectSub($closing, '_closing')->addSelect(['r.id', 'r.account_id', 'r.service', 'r.balance_minor', 'a.name']);
            $outer = DB::query()->fromSub($q, 'w');

            return $this->dataset($outer, $schema, ['_key' => 'w.id', 'id' => 'w.account_id', 'name' => 'w.name', 'service' => 'w.service', 'opening' => 'w._opening', 'credits' => 'w._credits', 'debits' => 'w._debits', 'closing' => 'w._closing', 'current' => 'w.balance_minor'], ['opening', 'credits', 'debits', 'closing', 'current']);
        }
        if ($id === 'wallets-requests') {
            $q = DB::table('finance_funding_requests as r')->where(function ($where) use ($scope): void {
                $where->whereIn('r.from_account_id', clone $scope)->orWhereIn('r.to_account_id', clone $scope);
            })->where('r.currency', $currency)->join('accounts as f', 'f.id', '=', 'r.from_account_id')->join('accounts as t', 't.id', '=', 'r.to_account_id')->leftJoin('users as u', 'u.id', '=', 'r.creator_id')->leftJoin('users as approver', 'approver.id', '=', 'r.reviewer_id');
            $this->period($q, $filters, 'r.created_at');

            return $this->dataset($q, $schema, ['_key' => 'r.id', 'id' => 'r.id', 'time' => 'r.created_at', 'from' => 'f.name', 'to' => 't.name', 'service' => 'r.service', 'amount' => 'r.amount_minor', 'status' => 'r.status', 'user' => 'u.name', 'approvedAmount' => "CASE WHEN r.status='approved' THEN r.amount_minor ELSE NULL END", 'purpose' => 'r.purpose', 'reason' => 'r.reason', 'reviewedAt' => 'r.reviewed_at', 'approver' => 'approver.name', 'transfer' => 'r.transaction_id', 'batch' => 'r.stock_batch_id', 'reference' => 'r.reference'], ['amount', 'approvedAmount']);
        }
        $q = DB::table('finance_entries as r')->join('finance_wallets as w', 'w.id', '=', 'r.wallet_id')->join('finance_transactions as t', 't.id', '=', 'r.transaction_id')->join('accounts as a', 'a.id', '=', 'w.account_id')->leftJoin('users as u', 'u.id', '=', 't.actor_id')->where('w.kind', 'account')->where('t.posted', true)->whereIn('w.account_id', $scope)->where('w.currency', $currency);
        $this->period($q, $filters, 'r.created_at');
        if ($id === 'wallets-ledger') {
            return $this->dataset($q, $schema, ['_key' => 'r.id', 'id' => 'r.id', 'group' => 't.reference', 'time' => 'r.created_at', 'account' => 'a.name', 'service' => 'w.service', 'kind' => 't.kind', 'amount' => 'r.amount_minor'], ['amount']);
        }
        if ($id === 'wallets-collections') {
            $q = DB::table('finance_entries as r')->join('finance_wallets as w', 'w.id', '=', 'r.wallet_id')->join('finance_transactions as t', 't.id', '=', 'r.transaction_id')
                ->join('audit_logs as settlement', function ($join): void {
                    $join->on('t.id', '=', DB::raw($this->json('settlement.details', 'transaction_id')))->where('settlement.action', 'invoices.settle');
                })->join('finance_invoices as invoice', 'invoice.id', '=', DB::raw($this->json('settlement.details', 'invoice_id')))->join('accounts as a', 'a.id', '=', 'invoice.account_id')->leftJoin('users as u', 'u.id', '=', 't.actor_id')
                ->where('invoice.kind', 'receivable')->whereIn('invoice.account_id', $scope)->where('t.kind', 'invoice-settlement')->where('t.posted', true)->where('r.amount_minor', '>', 0)->where('w.currency', $currency)->where('w.kind', 'account');
            $this->period($q, $filters, 'r.created_at');

            return $this->dataset($q, $schema, ['_key' => 'r.id', 'id' => 't.id', 'time' => 'r.created_at', 'account' => 'a.name', 'amount' => 'r.amount_minor', 'reference' => 't.reference', 'user' => 'u.name'], ['amount']);
        }
        if (in_array($id, ['wallets-transfers', 'wallets-groups'], true)) {
            $q = DB::table('finance_entries as r')->join('finance_wallets as w', 'w.id', '=', 'r.wallet_id')->join('finance_transactions as t', 't.id', '=', 'r.transaction_id')->join('accounts as a', 'a.id', '=', 'w.account_id')->leftJoin('users as u', 'u.id', '=', 't.actor_id')->where('w.kind', 'account')->where('t.posted', true)->where('w.currency', $currency)->whereIn('t.kind', ['transfer', 'funding'])->where('r.amount_minor', '>', 0)->join('finance_entries as debit', function ($join): void {
                $join->on('debit.transaction_id', '=', 't.id')->where('debit.amount_minor', '<', 0);
            })->join('finance_wallets as source', 'source.id', '=', 'debit.wallet_id')->leftJoin('accounts as f', 'f.id', '=', 'source.account_id')->where(function ($where) use ($scope): void {
                $where->whereIn('w.account_id', clone $scope)->orWhereIn('source.account_id', clone $scope);
            });
            $this->period($q, $filters, 'r.created_at');
            if ($id === 'wallets-groups') {
                $q->where('t.idempotency_key', 'like', 'BULK:%');
            }

            return $this->dataset($q, $schema, ['_key' => 't.id', 'id' => 't.id', 'time' => 'r.created_at', 'from' => 'f.name', 'to' => 'a.name', 'service' => 'w.service', 'amount' => 'r.amount_minor', 'status' => "'posted'", 'user' => 'u.name', 'transfer' => 't.id', 'reference' => 't.reference'], ['amount']);
        }

        return null;
    }

    private function stock(User $actor, array $schema, array $filters): ?ReportDataset
    {
        $id = $schema['id'];
        $permission = str_starts_with($id, 'claims') ? 'claims.view' : 'inventory.view';
        if (! Schema::hasTable('stock_batches') || ! $this->access->allows($actor, $permission)) {
            return null;
        }
        if ($id === 'inventory-products') {
            $q = DB::table('catalog_products as r')->join('catalog_providers as v', 'v.id', '=', 'r.provider_id')->whereIn('r.id', $this->products($actor, $filters))->where('r.currency', $filters['currency'] ?? 'IQD');

            return $this->dataset($q, $schema, ['_key' => 'r.id', 'id' => 'r.id', 'name' => 'r.name', 'provider' => 'v.name', 'kind' => 'r.kind', 'face' => 'r.face_value', 'currency' => 'r.currency', 'min' => 'r.minimum_price', 'dailyQty' => 'r.daily_quantity', 'dailyAmount' => 'r.daily_amount', 'active' => $this->active('r.status')]);
        }
        if ($id === 'inventory-providers') {
            $q = DB::table('catalog_providers as r')->whereIn('r.id', $this->access->products($actor, $filters)->select('provider_id'));

            return $this->dataset($q, $schema, ['_key' => 'r.id', 'id' => 'r.id', 'name' => 'r.name', 'supplier' => 'r.supplier', 'connection' => 'r.connection', 'active' => $this->active('r.status')]);
        }
        if ($id === 'inventory-copies') {
            $q = $this->auditBase($actor, $filters)->where('r.action', 'inventory.export');

            return $this->dataset($q, $schema, ['_key' => 'r.id', 'id' => 'r.id', 'time' => 'r.created_at', 'agent' => 'a.name', 'batch' => $this->json('r.details', 'batch_id'), 'quantity' => $this->json('r.details', 'quantity'), 'user' => 'u.name']);
        }
        $table = match ($id) {
            'inventory-cards' => 'stock_cards','claims' => 'stock_claims','claims-exports','claims-requests' => 'stock_withdrawals','inventory-cancellations' => 'stock_adjustments','inventory-orders' => 'stock_orders',default => 'stock_batches'
        };
        $q = DB::table($table.' as r')->join('accounts as a', 'a.id', '=', 'r.account_id')->whereIn('r.account_id', $this->accounts($actor, $filters));
        if ($table === 'stock_orders') {
            $q->leftJoin('catalog_providers as v', 'v.id', '=', 'r.provider_id')->leftJoin('users as u', 'u.id', '=', 'r.creator_id')->leftJoin('users as reviewer', 'reviewer.id', '=', 'r.reviewer_id')->whereIn('r.provider_id', $this->access->products($actor, $filters)->select('provider_id'));
            $this->period($q, $filters, 'r.created_at');

            return $this->dataset($q, $schema, ['_key' => 'r.id', 'id' => 'r.id', 'time' => 'r.created_at', 'agent' => 'a.name', 'provider' => 'v.name', 'city' => 'r.city', 'quantity' => 'r.quantity', 'rejected' => 'r.rejected', 'status' => 'r.status', 'user' => 'u.name', 'reviewed' => 'r.reviewed_at', 'reviewer' => 'reviewer.name', 'reason' => 'r.reason']);
        }
        $batch = $table === 'stock_batches' ? 'r' : 'b';
        if ($table !== 'stock_batches') {
            $q->join('stock_batches as b', 'b.id', '=', 'r.batch_id');
        }
        $q->join('catalog_products as p', 'p.id', '=', "$batch.product_id")->whereIn("$batch.product_id", $this->products($actor, $filters))->where("$batch.currency", $filters['currency'] ?? 'IQD');
        if (! $schema['snapshot']) {
            $this->period($q, $filters, 'r.created_at');
        }
        $base = ['_key' => 'r.id', 'id' => 'r.id', 'time' => 'r.created_at', 'agent' => 'a.name', 'batch' => $table === 'stock_batches' ? 'r.id' : 'r.batch_id', 'product' => 'p.name', 'quantity' => 'r.quantity', 'status' => 'r.status'];
        if ($id === 'inventory-cards') {
            return $this->dataset($q, $schema, ['_key' => 'r.id', 'internal' => 'r.id', 'serial' => 'r.serial', 'batch' => 'r.batch_id', 'agent' => 'a.name', 'product' => 'p.name', 'created' => 'r.created_at', 'expiry' => 'r.expiry', 'status' => 'r.status', 'sale' => 'r.sale_id', 'cost' => 'r.cost_minor'], ['cost']);
        }
        if ($id === 'claims') {
            return $this->dataset($q, $schema, $base + ['value' => 'r.credit_minor', 'reason' => 'r.reason', 'replacement' => 'r.replacement_batch_id', 'settled' => 'r.reviewed_at', 'compensation' => "CASE WHEN r.status='compensate' THEN r.quantity*b.load_price_minor ELSE NULL END", 'remaining' => "CASE WHEN r.status='pending' THEN r.quantity ELSE 0 END", 'replacedQuantity' => "CASE WHEN r.status='replace' THEN r.quantity ELSE 0 END", 'rejectedQuantity' => "CASE WHEN r.status='reject' THEN r.quantity ELSE 0 END", 'soldBefore' => '(SELECT COUNT(*) FROM stock_cards old_cards JOIN sales old_sale ON old_sale.id=old_cards.sale_id WHERE old_cards.batch_id=r.batch_id AND old_sale.issued_at IS NOT NULL AND old_sale.issued_at<=r.created_at)'], ['value', 'compensation']);
        }
        if (in_array($id, ['claims-exports', 'claims-requests'], true)) {
            $q->leftJoin('users as u', 'u.id', '=', 'r.creator_id')->leftJoin('users as reviewer', 'reviewer.id', '=', 'r.reviewer_id')->join('stock_claims as claim', 'claim.id', '=', 'r.claim_id');
            if ($id === 'claims-exports') {
                $q->whereNotNull('r.downloaded_at');
            }

            return $this->dataset($q, $schema, array_replace($base, ['time' => $id === 'claims-exports' ? 'r.downloaded_at' : 'r.created_at', 'quantity' => 'claim.quantity', 'value' => 'r.debit_minor', 'reason' => 'r.reason', 'user' => 'u.name', 'approver' => 'reviewer.name', 'until' => 'r.valid_until']), ['value']);
        }
        if ($id === 'inventory-cancellations') {
            return $this->dataset($q, $schema, $base + ['credit' => 'r.credit_minor', 'reason' => 'r.reason', 'restoredAt' => 'r.restored_at'], ['credit']);
        }
        if ($id === 'inventory-invoices') {
            $q->join('finance_invoices as invoice', 'invoice.id', '=', 'r.invoice_id');

            return $this->dataset($q, $schema, array_replace($base, ['id' => 'invoice.id', 'time' => 'invoice.created_at', 'amount' => 'invoice.amount_minor', 'cost' => 'r.cost_total_minor', 'profit' => $this->signed('invoice.amount_minor').' - '.$this->signed('r.cost_total_minor'), 'status' => 'invoice.status']), ['amount', 'cost', 'profit']);
        }
        if ($id === 'inventory-purchases') {
            return $this->dataset($q, $schema, $base + ['created' => 'r.created_at', 'supplier' => 'r.supplier', 'city' => 'r.city', 'rejected' => 'r.rejected', 'cost' => 'r.cost_minor', 'expenses' => 'r.expenses_minor', 'total' => 'r.cost_total_minor'], ['cost', 'expenses', 'total']);
        }
        $today = CarbonImmutable::now('Asia/Baghdad')->toDateString();
        $stats = DB::table('stock_cards as c')->select('c.batch_id')->groupBy('c.batch_id')->selectRaw('MIN(c.expiry) as expiry')->selectRaw("SUM(CASE WHEN c.status='Available' AND c.credit_held=0 AND c.sale_id IS NULL AND (c.expiry IS NULL OR c.expiry>?) THEN 1 ELSE 0 END) as available", [$today])->selectRaw("SUM(CASE WHEN c.status='Available' AND c.expiry<=? THEN 1 ELSE 0 END) as expired", [$today])->selectRaw("SUM(CASE WHEN c.status='Issued' THEN 1 ELSE 0 END) as issued")->selectRaw("SUM(CASE WHEN c.status IN ('Quarantined','Awaiting Replacement') THEN 1 ELSE 0 END) as held")->selectRaw("SUM(CASE WHEN c.status='Exported' THEN 1 ELSE 0 END) as exported")->selectRaw("SUM(CASE WHEN c.status='Cancelled' THEN 1 ELSE 0 END) as cancelled")->selectRaw("SUM(CASE WHEN c.status NOT IN ('Available','Issued','Quarantined','Awaiting Replacement','Exported','Cancelled') THEN 1 ELSE 0 END) as other")->selectRaw("SUM(CASE WHEN c.status='Available' AND c.credit_held=0 AND c.sale_id IS NULL AND (c.expiry IS NULL OR c.expiry>?) THEN c.cost_minor ELSE 0 END) as value", [$today]);
        $q->leftJoinSub($stats, 'stats', 'stats.batch_id', '=', 'r.id');
        foreach (['expiry', 'available', 'expired', 'issued', 'held', 'exported', 'cancelled', 'other', 'value'] as $column) {
            $base[$column] = $column === 'expiry' ? 'stats.expiry' : "COALESCE(stats.$column,0)";
        }
        $base['created'] = 'r.created_at';

        return $this->dataset($q, $schema, $base, ['value']);
    }

    private function network(User $actor, array $schema, array $filters): ?ReportDataset
    {
        $id = $schema['id'];
        if ($id === 'network-archive') {
            return $this->archives($actor, $schema, $filters);
        }
        if ($id === 'network-presence') {
            return $this->presence($actor, $schema, $filters);
        }
        if ($id === 'network-categories') {
            $q = DB::table('pos_types as r')->leftJoin('pos_reference_profiles as profile', 'profile.pos_type_id', '=', 'r.id')->leftJoin('accounts as a', 'a.id', '=', 'profile.account_id')->whereIn('a.id', $this->accounts($actor, $filters))->groupBy('r.id', 'r.name');

            return $this->dataset($q, $schema, ['_key' => 'r.id', 'id' => 'r.id', 'name' => 'r.name', 'count' => 'COUNT(a.id)']);
        }
        $q = DB::table('accounts as r')->whereIn('r.id', $this->accounts($actor, $filters))->leftJoinSub($this->access->accounts($actor)->select(['id', 'name']), 'parent', 'parent.id', '=', 'r.parent_id');
        if ($id === 'network-pos') {
            $q->where('r.type', 'pos');

            return $this->dataset($q, $schema, ['_key' => 'r.id', 'id' => 'r.id', 'name' => 'r.name', 'agent' => 'parent.name', 'city' => 'r.city', 'model' => 'r.device_model', 'serial' => 'r.serial', 'version' => 'r.app_version', 'active' => $this->active('r.status'), 'owner' => 'r.owner_name', 'phone' => 'r.phone', 'createdAt' => 'r.created_at', 'notes' => 'r.notes']);
        }
        $q->whereIn('r.type', ['main_agent', 'sub_agent', 'sub_branch']);

        return $this->dataset($q, $schema, ['_key' => 'r.id', '_type' => 'r.type', 'id' => 'r.id', 'name' => 'r.name', 'type' => "CASE r.type WHEN 'main_agent' THEN 'وكيل رئيسي' WHEN 'sub_agent' THEN 'وكيل فرعي' ELSE 'فرع فرعي' END", 'parent' => 'parent.name', 'city' => 'r.city', 'active' => $this->active('r.status')]);
    }

    private function prices(User $actor, array $schema, array $filters): ?ReportDataset
    {
        if ($schema['id'] === 'prices-policies' || ! $this->access->allows($actor, 'prices.view')) {
            return null;
        }
        if ($schema['id'] === 'prices') {
            $q = DB::table('accounts as a')->whereIn('a.id', $this->accounts($actor, $filters))->join('account_closure as ancestry', 'ancestry.descendant_id', '=', 'a.id')->join('accounts as main', 'main.id', '=', 'ancestry.ancestor_id')->where('main.type', 'main_agent')->join('finance_prices as r', 'r.account_id', '=', 'main.id')->join('catalog_products as p', 'p.id', '=', 'r.product_id')->whereIn('r.product_id', $this->products($actor, $filters))->where('r.currency', $filters['currency'] ?? 'IQD');

            return $this->dataset($q, $schema, ['_key' => $this->concat(['a.id', "':'", 'r.product_id']), 'agent' => 'a.name', 'product' => 'p.name', 'price' => 'r.price_minor', 'effective' => 'r.updated_at'], ['price']);
        }
        $q = DB::table('finance_price_requests as r')->join('accounts as a', 'a.id', '=', 'r.account_id')->leftJoin('users as u', 'u.id', '=', 'r.creator_id')->leftJoin('users as approver', 'approver.id', '=', 'r.reviewer_id')->whereIn('r.account_id', $this->accounts($actor, $filters));
        $this->period($q, $filters, 'r.created_at');
        if ($schema['id'] === 'prices-approvals') {
            return $this->dataset($q, $schema, ['_key' => 'r.id', 'id' => 'r.id', 'time' => 'r.created_at', 'creator' => 'u.name', 'approver' => 'approver.name', 'status' => 'r.status', 'changes' => (DB::getDriverName() === 'sqlite' ? 'JSON_ARRAY_LENGTH' : 'JSON_LENGTH').'(r.changes)']);
        }
        $index = $this->each($q, 'r.changes', 'change_row');
        $product = $this->json('change_row.value', 'product_id');
        $q->join('catalog_products as p', 'p.id', '=', DB::raw($product))->whereIn('p.id', $this->products($actor, $filters))->where('p.currency', $filters['currency'] ?? 'IQD');
        $old = $this->signed($this->json('change_row.value', 'old_minor'));
        $price = $this->signed($this->json('change_row.value', 'price_minor'));

        return $this->dataset($q, $schema, ['_key' => $this->concat(['r.id', "':'", $index]), 'id' => 'r.id', 'time' => 'r.created_at', 'agent' => 'a.name', 'product' => 'p.name', 'old' => $old, 'price' => $price, 'difference' => "$price - $old", 'creator' => 'u.name', 'approver' => 'approver.name', 'status' => 'r.status'], ['old', 'price', 'difference']);
    }

    private function effectivePermission(): string
    {
        $owner = "COALESCE((SELECT own.id FROM account_memberships own WHERE own.account_id=m.account_id AND own.kind='owner' AND own.status='active'),-1)";
        $ownerRole = "COALESCE((SELECT own.role_id FROM account_memberships own WHERE own.id=$owner),-1)";
        $base = "((m.kind='owner' AND EXISTS(SELECT 1 FROM role_permissions rp WHERE rp.role_id=m.role_id AND rp.permission_id=permission.id)) OR (m.kind='employee' AND EXISTS(SELECT 1 FROM permission_profiles prof JOIN permission_profile_permissions pp ON pp.permission_profile_id=prof.id WHERE prof.id=m.permission_profile_id AND prof.account_id=m.account_id AND prof.status='active' AND pp.permission_id=permission.id) AND (EXISTS(SELECT 1 FROM role_permissions rp WHERE rp.role_id=$ownerRole AND rp.permission_id=permission.id) OR EXISTS(SELECT 1 FROM membership_permissions op WHERE op.membership_id=$owner AND op.permission_id=permission.id AND op.allowed=1)) AND NOT EXISTS(SELECT 1 FROM membership_permissions op WHERE op.membership_id=$owner AND op.permission_id=permission.id AND op.allowed=0)))";

        return "CASE WHEN (($base OR (m.kind='owner' AND override.allowed=1)) AND NOT(m.kind='owner' AND COALESCE(override.allowed,1)=0) AND NOT EXISTS(SELECT 1 FROM account_permission_rules deny_rule JOIN account_closure closure ON closure.ancestor_id=deny_rule.target_account_id WHERE closure.descendant_id=m.account_id AND deny_rule.permission_id=permission.id AND deny_rule.allowed=0)) THEN 'مسموح' ELSE 'ممنوع' END";
    }

    private function members(User $actor, array $schema, array $filters): ?ReportDataset
    {
        if ($schema['id'] === 'users-times') {
            return $this->timePolicies($actor, $schema, $filters);
        }
        if (! $this->access->allows($actor, 'users.view')) {
            return null;
        }
        $q = DB::table('users as r')->join('account_memberships as m', 'm.user_id', '=', 'r.id')->join('accounts as a', 'a.id', '=', 'm.account_id')->join('roles as role', 'role.id', '=', 'm.role_id')->leftJoin('permission_profiles as profile', 'profile.id', '=', 'm.permission_profile_id')->whereIn('r.id', $this->users($actor, $filters));
        if ($schema['id'] === 'users') {
            $granted = '(SELECT COUNT(*) FROM permissions permission LEFT JOIN membership_permissions override ON override.membership_id=m.id AND override.permission_id=permission.id WHERE '.$this->effectivePermission()."='مسموح')";

            return $this->dataset($q, $schema, ['_key' => 'r.id', 'id' => 'r.id', 'name' => 'r.name', 'role' => 'role.name', 'email' => 'r.email', 'permissionType' => "CASE WHEN m.kind='employee' THEN profile.name ELSE 'صلاحيات المالك' END", 'active' => "CASE WHEN r.status='active' AND m.status='active' THEN 'مفعل' ELSE 'موقوف' END", 'scope' => 'a.name', 'granted' => $granted, 'allows' => '(SELECT COUNT(*) FROM membership_permissions o WHERE o.membership_id=m.id AND o.allowed=1)', 'denies' => '(SELECT COUNT(*) FROM membership_permissions o WHERE o.membership_id=m.id AND o.allowed=0)']);
        }
        $q->crossJoin('permissions as permission')->leftJoin('membership_permissions as override', function ($join): void {
            $join->on('override.membership_id', '=', 'm.id')->on('override.permission_id', '=', 'permission.id');
        });
        if ($schema['id'] === 'users-overrides') {
            $q->whereNotNull('override.permission_id')->where('m.kind', 'owner');
        }

        return $this->dataset($q, $schema, ['_key' => $this->concat(['r.id', "':'", 'permission.id']), 'user' => 'r.name', 'permission' => 'permission.name', 'key' => 'permission.name', 'module' => DB::getDriverName() === 'sqlite' ? "SUBSTR(permission.name,1,INSTR(permission.name,'.')-1)" : "SUBSTRING_INDEX(permission.name,'.',1)", 'override' => "CASE WHEN override.allowed=1 THEN 'سماح' WHEN override.allowed=0 THEN 'منع' ELSE NULL END", 'effective' => $this->effectivePermission()]);
    }

    private function supportReports(User $actor, array $schema, array $filters): ?ReportDataset
    {
        if (! Schema::hasTable('support_tickets') || ! $this->access->allows($actor, 'support.view')) {
            return null;
        }
        $tickets = $this->support->tickets($actor)->select('support_tickets.id');
        if (! empty($filters['agent_id']) || ! empty($filters['pos_id']) || ! empty($filters['city'])) {
            $tickets->whereExists(function ($q) use ($actor, $filters): void {
                $q->selectRaw('1')->from('support_ticket_participants')->whereColumn('ticket_id', 'support_tickets.id')->whereIn('account_id', $this->accounts($actor, $filters));
            });
        }
        if ($schema['id'] === 'support-contacts') {
            $q = DB::table('support_phone_profiles as r')->join('accounts as a', 'a.id', '=', 'r.account_id')->whereIn('r.account_id', $this->accounts($actor, $filters));
            $index = $this->each($q, 'r.phones', 'phone_row');

            return $this->dataset($q, $schema, ['_key' => $this->concat(['r.account_id', "':'", $index]), 'account' => 'a.name', 'label' => $this->json('phone_row.value', 'label'), 'phone' => $this->json('phone_row.value', 'phone')]);
        }
        if ($schema['id'] === 'support-replies') {
            $q = DB::table('support_messages as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id')->whereIn('r.ticket_id', $tickets)->whereIn('r.id', $this->support->messages($actor)->where('is_opening', false)->select('support_messages.id'));
            $this->period($q, $filters, 'r.created_at');

            return $this->dataset($q, $schema, ['_key' => 'r.id', 'ticket' => 'r.ticket_id', 'time' => 'r.created_at', 'user' => 'u.name', 'body' => 'r.body']);
        }
        $q = DB::table('support_tickets as r')->join('accounts as a', 'a.id', '=', 'r.origin_id')->leftJoin('users as sender', 'sender.id', '=', 'r.sender_id')->leftJoin('accounts as recipient', 'recipient.id', '=', 'r.recipient_id')->whereIn('r.id', $tickets);
        $this->period($q, $filters, 'r.created_at');
        $replies = $this->support->messages($actor)->where('is_opening', false)->whereColumn('ticket_id', 'r.id')->selectRaw('COUNT(*)');
        $q->selectSub($replies, '_replies')->addSelect(['r.id', 'r.created_at', 'r.title', 'r.description', 'r.attachment_id', 'r.status', 'a.name as agent_name', 'sender.name as sender_name', 'recipient.name as recipient_name']);
        $outer = DB::query()->fromSub($q, 'ticket_rows');

        return $this->dataset($outer, $schema, ['_key' => 'ticket_rows.id', 'id' => 'ticket_rows.id', 'time' => 'ticket_rows.created_at', 'agent' => 'ticket_rows.agent_name', 'title' => 'ticket_rows.title', 'description' => 'ticket_rows.description', 'attachment' => "CASE WHEN ticket_rows.attachment_id IS NOT NULL THEN 'مرفقة' ELSE 'لا يوجد' END", 'senderName' => 'ticket_rows.sender_name', 'recipientName' => 'ticket_rows.recipient_name', 'status' => 'ticket_rows.status', 'replies' => 'ticket_rows._replies']);
    }

    private function auditBase(User $actor, array $filters): Builder
    {
        $scope = $this->accounts($actor, $filters);
        $q = DB::table('audit_logs as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id')->leftJoin('accounts as a', 'a.id', '=', 'r.subject_account_id')->where(function ($where) use ($scope): void {
            $where->whereIn('r.subject_account_id', clone $scope)->orWhere(function ($own) use ($scope): void {
                $own->whereNull('r.subject_account_id')->whereIn('r.account_id', clone $scope);
            });
        });

        return $this->period($q, $filters, 'r.created_at');
    }

    private function administration(User $actor, array $schema, array $filters): ?ReportDataset
    {
        $id = $schema['id'];
        if ($id === 'operations-integrations') {
            return $this->integrations($actor, $schema, $filters);
        }
        if ($id === 'operations-security') {
            return $this->securityEvents($actor, $schema, $filters);
        }
        if (in_array($id, ['audit', 'audit-changes'], true)) {
            if (! $this->access->allows($actor, 'audit.view')) {
                return null;
            }
            $q = $this->auditBase($actor, $filters);
            $fields = ['_key' => 'r.id', 'id' => 'r.id', 'time' => 'r.created_at', 'name' => 'u.name', 'user' => 'u.name', 'action' => 'r.action', 'entity' => 'r.subject_account_id', 'source' => 'r.portal'];
            if ($id === 'audit-changes') {
                $q->whereNotNull('r.details')->whereNotNull(DB::raw($this->json('r.details', 'changes')));
                $index = $this->each($q, $this->json('r.details', 'changes'), 'change_row');
                $fields['_key'] = $this->concat(['r.id', "':'", $index]);
                $fields['field'] = $this->json('change_row.value', 'field');
                $fields['before'] = $this->json('change_row.value', 'before');
                $fields['after'] = $this->json('change_row.value', 'after');
                $q->whereIn(DB::raw($fields['field']), ['name', 'status', 'city', 'phone', 'parent_id', 'permission_id', 'allowed', 'product_id', 'quantity', 'version']);
            }

            return $this->dataset($q, $schema, $fields);
        }
        if ($id === 'operations-notifications' && Schema::hasTable('notices') && $this->access->allows($actor, 'notifications.view')) {
            $q = DB::table('notices as r')->leftJoin('users as u', 'u.id', '=', 'r.sender_id')->whereIn('r.id', $this->notifications->visible($actor)->select('notices.id'));
            $this->period($q, $filters, 'r.created_at');
            $counts = DB::table('notice_recipients')->whereIn('user_id', $this->users($actor, $filters))->select('notice_id')->groupBy('notice_id')->selectRaw('SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) as readers')->selectRaw('SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) as unread');
            $q->leftJoinSub($counts, 'counts', 'counts.notice_id', '=', 'r.id');

            return $this->dataset($q, $schema, ['_key' => 'r.id', 'id' => 'r.id', 'time' => 'r.created_at', 'user' => 'u.name', 'title' => 'r.title', 'body' => 'r.body', 'attachment' => "CASE WHEN r.attachment_id IS NOT NULL THEN 'مرفقة' ELSE 'لا يوجد' END", 'target' => 'r.page', 'readers' => 'COALESCE(counts.readers,0)', 'unread' => 'COALESCE(counts.unread,0)']);
        }
        if ($id === 'operations-sessions' && $this->access->allows($actor, 'users.view')) {
            $q = DB::table('sessions as r')->join('users as u', 'u.id', '=', 'r.user_id')->whereIn('r.user_id', $this->users($actor, $filters));
            [$from,$to] = $this->dates($filters);
            if ($from !== null) {
                $q->where('r.last_activity', '>=', strtotime($from.' UTC'));
            }
            if ($to !== null) {
                $q->where('r.last_activity', '<=', strtotime($to.' UTC'));
            }
            $last = DB::getDriverName() === 'sqlite' ? "DATETIME(r.last_activity,'unixepoch')" : 'FROM_UNIXTIME(r.last_activity)';
            $expires = now()->subMinutes((int) config('session.lifetime'))->getTimestamp();

            return $this->dataset($q, $schema, ['_key' => 'r.id', 'user' => 'u.name', 'lastActivity' => $last, 'status' => "CASE WHEN r.last_activity>$expires THEN 'صالحة' ELSE 'منتهية' END"]);
        }
        if ($id === 'operations-printing' && $this->access->allows($actor, 'printing.view') && Schema::hasTable('sales_print_rules')) {
            $q = DB::table('sales_print_rules as r');
            $this->each($q, 'r.targets', 'target_row');
            $q->join('accounts as target_account', 'target_account.id', '=', DB::raw($this->json('target_row.value', 'id')))->whereIn('target_account.id', $this->accounts($actor, $filters))->groupBy('r.id', 'r.name', 'r.active', 'r.policy');

            return $this->dataset($q, $schema, ['_key' => 'r.id', 'id' => 'r.id', 'name' => 'r.name', 'targets' => 'GROUP_CONCAT(DISTINCT target_account.name)', 'status' => "CASE WHEN r.active=1 THEN 'مفعل' ELSE 'موقوف' END", 'failedRetries' => $this->json('r.policy', 'failed_retries'), 'maxCards' => $this->json('r.policy', 'max_cards'), 'intervalSeconds' => $this->json('r.policy', 'interval_seconds'), 'dailyCards' => $this->json('r.policy', 'daily_cards'), 'dailyMode' => $this->json('r.policy', 'daily_mode')]);
        }
        if ($id === 'operations' && $this->access->allows($actor, 'settings.view') && Schema::hasTable('sales_policy')) {
            $q = DB::table('sales_policy as r');
            $index = $this->each($q, "JSON_ARRAY('sales_enabled','printing_enabled','velocity_seconds','min_app_version','min_os_version','reprint_limit')", 'setting_row');
            $name = DB::getDriverName() === 'sqlite' ? 'setting_row.value' : 'JSON_UNQUOTE(setting_row.value)';
            $path = $this->concat(["'$.'", $name]);
            $value = "JSON_EXTRACT(r.settings,$path)";
            if (DB::getDriverName() !== 'sqlite') {
                $value = "JSON_UNQUOTE($value)";
            }

            return $this->dataset($q, $schema, ['_key' => $index, 'key' => $name, 'value' => $value]);
        }

        return null;
    }
}
