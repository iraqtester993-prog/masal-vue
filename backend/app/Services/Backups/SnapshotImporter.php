<?php

namespace App\Services\Backups;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class SnapshotImporter
{
    public function __construct(private SnapshotSchema $schema, private SnapshotArchive $archive) {}

    public function preview(string $path, Connection $source): array
    {
        $verified = $this->archive->read($path);
        $this->sameSchema($verified, $source);
        if (! extension_loaded('pdo_sqlite')) {
            return ['manifest' => $verified['manifest'], 'reference_verified' => false, 'reason' => 'فحص العلاقات يحتاج SQLite على السيرفر أو منصة فحص مستقلة.'];
        }
        $id = (string) Str::uuid();
        $folder = 'backups/validation/'.$id;
        Storage::disk('local')->makeDirectory($folder);
        $database = Storage::disk('local')->path($folder.'/validation.sqlite');
        touch($database);
        @chmod($database, 0600);
        $name = 'backup_validation_'.$id;
        config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true]]);
        try {
            $this->restore($path, $name, $source, $folder.'/assets', $verified);

            return ['manifest' => $verified['manifest'], 'reference_verified' => true, 'reason' => null];
        } finally {
            DB::purge($name);
            config(['database.connections.'.$name => null]);
            Storage::disk('local')->deleteDirectory($folder);
        }
    }

    public function restore(string $path, string $connection, Connection $source, string $assetFolder, ?array $verified = null): array
    {
        $verified ??= $this->archive->read($path);
        $this->sameSchema($verified, $source);
        $destination = DB::connection($connection);
        if ($destination->getDatabaseName() === $source->getDatabaseName() && $destination->getConfig('host') === $source->getConfig('host')) {
            throw new RuntimeException('يُمنع الاسترجاع داخل قاعدة النظام الحالية.');
        }
        if ($destination->getSchemaBuilder()->getTables($this->schema->tableSchema($destination)) !== []) {
            throw new RuntimeException('قاعدة الاسترجاع يجب أن تكون جديدة وفارغة.');
        }
        $originalDefault = DB::getDefaultConnection();
        try {
            DB::setDefaultConnection($connection);
            $paths = [];
            foreach ($verified['header']['schema']['migrations'] as $migration) {
                if (! preg_match('/^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+$/D', $migration) || ! is_file($file = database_path('migrations/'.$migration.'.php'))) {
                    throw new RuntimeException('ملف ترحيل النسخة غير موجود.');
                }
                $paths[] = $file;
            }
            if (Artisan::call('migrate', ['--database' => $connection, '--path' => $paths, '--realpath' => true, '--force' => true]) !== 0) {
                throw new RuntimeException('تعذر إنشاء مخطط قاعدة الاسترجاع.');
            }
            $this->import($path, $destination, $verified, $assetFolder);
            $this->validate($destination, $verified['manifest']['counts']);

            return $verified['manifest'];
        } finally {
            DB::setDefaultConnection($originalDefault);
        }
    }

    private function sameSchema(array $verified, Connection $source): void
    {
        $current = $this->schema->describe($source);
        if (! hash_equals($current['fingerprint'], $verified['manifest']['schema_fingerprint'] ?? '')) {
            throw new RuntimeException('مخطط النسخة يختلف عن إصدار النظام الحالي؛ يلزم مسار ترحيل مراجع.');
        }
    }

    private function import(string $path, Connection $db, array $verified, string $assetFolder): void
    {
        $tables = $verified['header']['schema']['tables'];
        $order = $this->schema->order($tables);
        $expectedTable = 0;
        $activeTable = null;
        $file = null;
        $fileHash = null;
        $fileBytes = 0;
        $filePath = null;
        $db->statement('CREATE TEMPORARY TABLE backup_parent_links (id BIGINT PRIMARY KEY, parent_id BIGINT NOT NULL)');
        $db->statement('CREATE TEMPORARY TABLE backup_wallet_targets (id BIGINT PRIMARY KEY, balance_minor BIGINT NOT NULL, held_minor BIGINT NOT NULL, version BIGINT NOT NULL)');
        $db->statement('CREATE TEMPORARY TABLE backup_file_refs (path VARCHAR(255) PRIMARY KEY, restored INTEGER NOT NULL DEFAULT 0)');
        try {
            $db->beginTransaction();
            $this->archive->read($path, function (array $record) use ($db, $tables, $order, &$expectedTable, &$activeTable, $assetFolder, &$file, &$fileHash, &$fileBytes, &$filePath): void {
                if ($file !== null && ! in_array($record['kind'], ['file-chunk', 'file-end'], true)) {
                    throw new RuntimeException('مرفق النسخة غير مكتمل.');
                }
                if ($record['kind'] === 'table') {
                    $this->finishTable($db, $activeTable);
                    if (($order[$expectedTable++] ?? null) !== $record['table']) {
                        throw new RuntimeException('ترتيب علاقات النسخة غير صالح.');
                    }
                    $activeTable = $record['table'];

                    return;
                }
                if ($record['kind'] === 'row') {
                    $table = $record['table'];
                    $row = $record['row'];
                    if ($table !== $activeTable || array_diff(array_keys($row), array_column($tables[$table]['columns'], 'name')) !== [] || array_diff(array_column($tables[$table]['columns'], 'name'), array_keys($row)) !== []) {
                        throw new RuntimeException('أعمدة السجل لا تطابق مخطط النسخة.');
                    }
                    foreach ($this->schema->assetPaths($table, $row) as $asset) {
                        $db->table('backup_file_refs')->insertOrIgnore(['path' => $asset]);
                    }
                    if ($table === 'accounts' && $row['parent_id'] !== null) {
                        $db->table('backup_parent_links')->insert(['id' => $row['id'], 'parent_id' => $row['parent_id']]);
                        $row['parent_id'] = null;
                    }
                    if ($table === 'finance_wallets') {
                        $target = array_intersect_key($row, array_flip(['id', 'balance_minor', 'held_minor', 'version']));
                        if ((int) $target['version'] < 1 || ((int) $target['version'] === 1 && ((int) $target['balance_minor'] !== 0 || (int) $target['held_minor'] !== 0))) {
                            throw new RuntimeException('إصدار المحفظة لا يطابق رصيدها.');
                        }
                        $db->table('backup_wallet_targets')->insert($target);
                        $row['balance_minor'] = 0;
                        $row['held_minor'] = 0;
                        $row['version'] = max(1, (int) $target['version'] - 1);
                    }
                    if ($table === 'finance_transactions') {
                        if (! in_array($row['posted'], [1, true, '1'], true)) {
                            throw new RuntimeException('توجد حركة مالية غير مرحّلة.');
                        }
                        $row['posted'] = 0;
                    }
                    if (in_array($table, ['catalog_meta', 'finance_policy', 'sales_policy', 'company_profiles', 'pos_types'], true)) {
                        $db->table($table)->updateOrInsert(array_intersect_key($row, array_flip($tables[$table]['primary'])), $row);
                    } else {
                        $db->table($table)->insert($row);
                    }

                    return;
                }
                if ($record['kind'] === 'file-start') {
                    $filePath = $record['path'] ?? '';
                    $this->schema->safeAsset($filePath);
                    if (! $db->table('backup_file_refs')->where('path', $filePath)->exists()) {
                        throw new RuntimeException('مرفق غير مرتبط بسجل النسخة.');
                    }
                    $target = $assetFolder.'/'.$filePath;
                    Storage::disk('local')->makeDirectory(dirname($target));
                    $file = fopen(Storage::disk('local')->path($target), 'wb');
                    if ($file === false) {
                        throw new RuntimeException('تعذر إنشاء مرفق الفحص.');
                    }
                    @chmod(Storage::disk('local')->path($target), 0600);
                    $fileHash = hash_init('sha256');
                    $fileBytes = 0;
                } elseif ($record['kind'] === 'file-chunk') {
                    $chunk = base64_decode($record['data'] ?? '', true);
                    if ($file === null || $chunk === false || strlen($chunk) > 65536 || fwrite($file, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('محتوى المرفق غير صالح.');
                    }
                    hash_update($fileHash, $chunk);
                    $fileBytes += strlen($chunk);
                } elseif ($record['kind'] === 'file-end') {
                    if ($file === null || $fileBytes !== ($record['bytes'] ?? null) || ! hash_equals(hash_final($fileHash), $record['sha256'] ?? '')) {
                        throw new RuntimeException('فشل فحص بصمة المرفق.');
                    }
                    fclose($file);
                    $file = null;
                    $db->table('backup_file_refs')->where('path', $filePath)->update(['restored' => 1]);
                }
            });
            $this->finishTable($db, $activeTable);
            if ($file !== null || $expectedTable !== count($order) || $db->table('backup_file_refs')->where('restored', 0)->exists()) {
                throw new RuntimeException('النسخة أو مرفقاتها غير مكتملة.');
            }
            $db->commit();
        } catch (\Throwable $failure) {
            $db->rollBack();
            throw $failure;
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
        }
    }

    private function finishTable(Connection $db, ?string $table): void
    {
        if ($table === 'users') {
            $db->table('users')->update(['remember_token' => null, 'session_version' => $db->raw('session_version + 1')]);
        }
        if ($table === 'accounts') {
            foreach ($this->schema->rows($db, 'backup_parent_links', ['id']) as $link) {
                $db->table('accounts')->where('id', $link['id'])->update(['parent_id' => $link['parent_id']]);
            }
        }
        if ($table === 'finance_entries') {
            foreach ($this->schema->rows($db, 'finance_transactions', ['id']) as $transaction) {
                $db->table('finance_transactions')->where('id', $transaction['id'])->update(['posted' => 1]);
            }
            foreach ($this->schema->rows($db, 'backup_wallet_targets', ['id']) as $target) {
                if ((int) $target['version'] > 1) {
                    $db->table('finance_wallets')->where('id', $target['id'])->update(['balance_minor' => $target['balance_minor'], 'held_minor' => $target['held_minor'], 'version' => $target['version']]);
                }
            }
        }
        if ($table === 'user_presences') {
            $db->table('user_presences')->update(['connected' => false, 'sharing' => false, 'consent_version' => $db->raw('consent_version + 1')]);
        }
    }

    private function validate(Connection $db, array $counts): void
    {
        foreach ($counts as $table => $count) {
            if ($db->table($table)->count() !== $count) {
                throw new RuntimeException('عدد سجلات قاعدة الاسترجاع لا يطابق النسخة الموقعة.');
            }
        }
        if ($db->getDriverName() === 'sqlite' && $db->select('PRAGMA foreign_key_check') !== []) {
            throw new RuntimeException('فشل فحص علاقات قاعدة البيانات.');
        }
        if (isset($counts['digital_orders']) && $db->table('digital_orders')->where(fn ($orders) => $orders->where('reservation_active', true)->orWhereIn('status', ['pending', 'review']))->exists()) {
            throw new RuntimeException('تحتوي النسخة طلبات رقمية لم تحسمها الشركة؛ لا يجوز استرجاعها وإعادة تنفيذ شراء سابق.');
        }
        if (isset($counts['digital_provider_attempts']) && $db->table('digital_provider_attempts as attempt')->join('digital_orders as digital_order', 'digital_order.id', '=', 'attempt.order_id')
            ->where(fn ($attempts) => $attempts->whereNotNull('attempt.active_order_id')->orWhere('attempt.status', 'dispatching')->orWhere(fn ($unknown) => $unknown->where('attempt.status', 'unknown')->whereNotIn('digital_order.status', ['succeeded', 'failed', 'refunded'])))->exists()) {
            throw new RuntimeException('تحتوي النسخة محاولة شراء رقمية جارية أو غير محسومة؛ أوقف الاسترجاع وراجع نتيجة الشركة.');
        }
        $walletSums = $db->table('finance_entries')->selectRaw('wallet_id, SUM(amount_minor) AS total')->groupBy('wallet_id');
        if ($db->table('finance_wallets as w')->leftJoinSub($walletSums, 'e', 'e.wallet_id', '=', 'w.id')->whereRaw('w.balance_minor <> COALESCE(e.total,0)')->exists()) {
            throw new RuntimeException('رصيد المحفظة لا يطابق السجل.');
        }
        $history = $db->table('finance_entries')->selectRaw('id, balance_after_minor, SUM(amount_minor) OVER (PARTITION BY wallet_id ORDER BY id ROWS UNBOUNDED PRECEDING) AS expected');
        if ($db->query()->fromSub($history, 'history')->whereColumn('balance_after_minor', '<>', 'expected')->exists()) {
            throw new RuntimeException('أرصدة حركات المحفظة غير متسلسلة.');
        }
        $owner = $db->table('account_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('accounts as a', 'a.id', '=', 'm.account_id')->where('m.kind', 'owner')->where('a.type', 'system')->where('a.status', 'active')->where('m.status', 'active')->where('u.status', 'active')->first(['u.password']);
        if (! $owner || password_get_info($owner->password)['algo'] === null) {
            throw new RuntimeException('النسخة لا تحتوي مدير نظام فعال.');
        }
        $count = $db->table('accounts')->count();
        $cycle = $db->selectOne('WITH RECURSIVE walk AS (SELECT id AS root,id,parent_id,1 AS depth FROM accounts UNION ALL SELECT walk.root,a.id,a.parent_id,walk.depth+1 FROM walk JOIN accounts a ON a.id=walk.parent_id WHERE walk.depth<=?) SELECT COUNT(*) AS invalid FROM walk WHERE depth>?', [$count, $count]);
        if ((int) $cycle->invalid > 0) {
            throw new RuntimeException('توجد دورة في شجرة الحسابات.');
        }
        $allowed = "(child.type='main_agent' AND parent.type='system') OR (child.type='sub_agent' AND parent.type='main_agent') OR (child.type='sub_branch' AND parent.type='sub_agent') OR (child.type='pos' AND parent.type IN ('main_agent','sub_agent','sub_branch'))";
        if ($db->table('accounts as child')->leftJoin('accounts as parent', 'parent.id', '=', 'child.parent_id')->whereRaw("(child.type='system' AND child.parent_id IS NOT NULL) OR (child.type<>'system' AND (child.parent_id IS NULL OR NOT ($allowed)))")->exists()) {
            throw new RuntimeException('مستوى الحساب لا يطابق حسابه الأعلى.');
        }
        $expected = 'WITH RECURSIVE expected AS (SELECT id AS ancestor_id,id AS descendant_id,0 AS depth FROM accounts UNION ALL SELECT expected.ancestor_id,a.id,expected.depth+1 FROM expected JOIN accounts a ON a.parent_id=expected.descendant_id) ';
        $missing = $db->selectOne($expected.'SELECT COUNT(*) AS invalid FROM expected e LEFT JOIN account_closure c ON c.ancestor_id=e.ancestor_id AND c.descendant_id=e.descendant_id AND c.depth=e.depth WHERE c.ancestor_id IS NULL');
        $extra = $db->selectOne($expected.'SELECT COUNT(*) AS invalid FROM account_closure c LEFT JOIN expected e ON c.ancestor_id=e.ancestor_id AND c.descendant_id=e.descendant_id AND c.depth=e.depth WHERE e.ancestor_id IS NULL');
        if ((int) $missing->invalid > 0 || (int) $extra->invalid > 0) {
            throw new RuntimeException('علاقات نطاق الحسابات لا تطابق الشجرة.');
        }
    }
}
