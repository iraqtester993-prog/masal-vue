<?php

namespace App\Services\Backups;

use App\Contracts\Backups\RestorePlatform;
use Dotenv\Dotenv;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class CpanelRestorePlatform implements RestorePlatform
{
    public function capabilities(): array
    {
        try {
            $paths = $this->paths();
            $environment = $this->environment($paths['environment']);
            $connection = $this->configuration();
            if ($environment['values']['DB_DATABASE'] !== $connection['database']) {
                throw new RuntimeException('اتصال قاعدة البيانات لا يطابق ملف البيئة الخاص.');
            }
            if (! is_executable(config('backups.uapi')) || ! is_executable(config('backups.php_binary')) || ! function_exists('proc_open')) {
                throw new RuntimeException('صلاحيات تنفيذ عامل الاسترجاع أو إدارة قواعد cPanel غير متاحة.');
            }

            return ['prepare' => true, 'switch' => true, 'reason' => null];
        } catch (Throwable $failure) {
            return ['prepare' => false, 'switch' => false, 'reason' => $failure instanceof RuntimeException ? $failure->getMessage() : 'تعذر التحقق من إعدادات الاسترجاع.'];
        }
    }

    /** @return array{environment: string, folder: string, maintenance: string} */
    protected function paths(): array
    {
        $root = $this->canonical(realpath(config('backups.runtime_root')) ?: '');
        $base = $this->canonical(realpath(base_path()) ?: '');
        $environment = $this->canonical(realpath(base_path('.env')) ?: '');
        if ($root === '' || ! str_starts_with($base, $root.'/releases/') || $environment !== $root.'/shared/.env'
            || $this->canonical(realpath(app()->environmentFilePath()) ?: '') !== $environment || is_link($environment)
            || ! is_writable($environment) || $this->canonical(realpath(storage_path()) ?: '') !== $root.'/shared/storage'
            || ! is_writable(storage_path())) {
            throw new RuntimeException('مسارات السورس والبيئة والخزن الخاص لا تطابق مسارات الاستضافة المعتمدة.');
        }
        foreach ([$root.'/.local', $root.'/.local/restore'] as $folder) {
            if (is_link($folder) || (! is_dir($folder) && ! mkdir($folder, 0700) && ! is_dir($folder)) || $this->canonical(realpath($folder) ?: '') !== $folder) {
                throw new RuntimeException('تعذر تجهيز مجلد استرجاع خاص وآمن.');
            }
            chmod($folder, 0700);
        }

        return ['environment' => $environment, 'folder' => $root.'/.local/restore', 'maintenance' => $root.'/shared/storage/framework/down'];
    }

    private function canonical(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        $connection = config('database.connections.mysql', []);
        $user = config('backups.cpanel_user');
        if (config('database.default') !== 'mysql' || ($connection['driver'] ?? null) !== 'mysql' || ! empty($connection['url'])
            || ! is_string($user) || ! preg_match('/^[a-z][a-z0-9]{0,15}$/D', $user)
            || ! str_starts_with($connection['username'] ?? '', $user.'_') || ! preg_match('/^[a-z0-9_]{1,64}$/D', $connection['database'] ?? '')
            || ! str_starts_with($connection['database'], $user.'_') || ! in_array($connection['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)
            || config('app.maintenance.driver', 'file') !== 'file') {
            throw new RuntimeException('الاسترجاع يحتاج اتصال MySQL محليًا صريحًا وصيانة بالملف؛ DB_URL غير مدعوم.');
        }

        return $connection;
    }

    /** @return array{content: string, values: array<string, string|null>} */
    private function environment(string $path): array
    {
        if (! is_file($path) || is_link($path) || filesize($path) > 1048576) {
            throw new RuntimeException('ملف البيئة الخاص غير صالح للاسترجاع.');
        }
        $content = file_get_contents($path);
        try {
            $values = Dotenv::parse($content);
        } catch (Throwable) {
            throw new RuntimeException('تعذر قراءة إعدادات البيئة الخاصة.');
        }
        if (! empty($values['DB_URL']) || ($values['DB_CONNECTION'] ?? null) !== 'mysql'
            || preg_match_all('/^\s*(?:export\s+)?DB_DATABASE\s*=/m', $content) !== 1
            || preg_match_all('/^DB_DATABASE=.*$/m', $content) !== 1) {
            throw new RuntimeException('ملف البيئة يحتاج تعريفًا صريحًا واحدًا لقاعدة MySQL دون DB_URL.');
        }

        return ['content' => $content, 'values' => $values];
    }

    private function job(string $jobId): string
    {
        if (! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $jobId)) {
            throw new RuntimeException('معرف عملية الاسترجاع غير صالح.');
        }

        return $jobId;
    }

    private function identifier(string $jobId): string
    {
        return config('backups.cpanel_user').'_mr_'.substr(hash('sha256', $this->job($jobId)), 0, 12);
    }

    private function console(): void
    {
        if (! app()->runningInConsole()) {
            throw new RuntimeException('تبديل قاعدة البيانات ينفذ من عامل الاسترجاع فقط.');
        }
    }

    /** @return array<string, false> */
    private function childEnvironment(): array
    {
        $keys = array_keys($this->environment($this->paths()['environment'])['values']);
        $keys = array_merge($keys, ['DB_URL', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SOCKET', 'APP_ENV', 'APP_KEY', 'APP_BASE_PATH', 'APP_CONFIG_CACHE', 'LARAVEL_ENV']);

        return array_fill_keys(array_unique($keys), false);
    }

    private function api(string $action, array $parameters = []): array
    {
        $arguments = [config('backups.uapi'), '--output=json', 'Mysql', $action];
        foreach ($parameters as $key => $value) {
            $arguments[] = $key.'='.$value;
        }
        $result = Process::timeout(60)->run($arguments);
        $payload = json_decode($result->output(), true);
        if (! $result->successful() || ($payload['result']['status'] ?? null) !== 1) {
            throw new RuntimeException('رفضت الاستضافة تجهيز قاعدة الاسترجاع المستقلة.');
        }

        return $payload['result']['data'] ?? [];
    }

    public function createScratch(string $jobId): array
    {
        $this->console();
        $this->paths();
        $connection = $this->configuration();
        if ($this->environment($this->paths()['environment'])['values']['DB_DATABASE'] !== $connection['database']) {
            throw new RuntimeException('اتصال قاعدة البيانات لا يطابق ملف البيئة الخاص.');
        }
        $identifier = $this->identifier($jobId);
        foreach ($this->api('list_databases') as $record) {
            if (($record['database'] ?? null) === $identifier) {
                throw new RuntimeException('قاعدة هذه العملية موجودة مسبقًا؛ راجع العملية ولا تعِد تهيئتها.');
            }
        }
        $this->api('create_database', ['name' => $identifier]);
        $this->api('set_privileges_on_database', ['user' => $connection['username'], 'database' => $identifier, 'privileges' => 'ALL PRIVILEGES']);
        $connection['database'] = $identifier;
        $connection['url'] = null;

        return ['identifier' => $identifier, 'connection' => $connection];
    }

    private function write(string $path, string $content): void
    {
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('ملف حماية الاسترجاع موجود أو غير صالح؛ يُمنع استبداله.');
        }
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('ملف حماية الاسترجاع موجود أو تعذر إنشاؤه.');
        }
        try {
            chmod($path, 0600);
            if (fwrite($handle, $content) !== strlen($content) || ! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                throw new RuntimeException('تعذر حفظ ملف حماية الاسترجاع.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $lock */
    private function owner($lock, ?array $owner): void
    {
        $content = $owner === null ? '' : json_encode($owner, JSON_THROW_ON_ERROR);
        rewind($lock);
        if (! ftruncate($lock, 0) || fwrite($lock, $content) !== strlen($content) || ! fflush($lock) || (function_exists('fsync') && ! fsync($lock))) {
            throw new RuntimeException('تعذر تثبيت ملكية عملية الاسترجاع.');
        }
    }

    private function exclusive(string $jobId, bool $begin, callable $action): mixed
    {
        $this->console();
        $jobId = $this->job($jobId);
        $paths = $this->paths();
        $path = $paths['folder'].'/active.lock';
        if (is_link($path)) {
            throw new RuntimeException('ملف قفل الاسترجاع غير صالح.');
        }
        $lock = fopen($path, 'c+');
        if ($lock === false) {
            throw new RuntimeException('تعذر فتح قفل الاسترجاع.');
        }
        try {
            chmod($path, 0600);
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('عامل استرجاع آخر يملك القفل الحصري.');
            }
            rewind($lock);
            $content = stream_get_contents($lock, 4097);
            $owner = $content === '' ? null : json_decode($content, true, 8, JSON_THROW_ON_ERROR);
            if (($begin && $owner !== null) || (! $begin && ($owner['job_id'] ?? null) !== $jobId)) {
                throw new RuntimeException('عملية أخرى تتحكم بالاسترجاع؛ يُمنع تغيير صيانتها أو بوابة كتابتها.');
            }

            return $action($paths, $lock, $owner);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function artisan(array $arguments): void
    {
        $result = Process::path(base_path())->env($this->childEnvironment())->timeout(60)
            ->run(array_merge([config('backups.php_binary'), base_path('artisan')], $arguments));
        if (! $result->successful()) {
            throw new RuntimeException('تعذر تحديث حالة النظام أثناء الاسترجاع.');
        }
    }

    private function gate(Connection $db, string $jobId, int $version, bool $release): void
    {
        $db->transaction(function () use ($db, $jobId, $version, $release): void {
            $gate = $db->table('runtime_write_gate')->where('id', 1)->lockForUpdate()->first();
            if (! $gate || ! $gate->blocked || $gate->restore_job_id !== $jobId || (int) $gate->version !== $version) {
                throw new RuntimeException('إصدار بوابة الكتابة أو مالكها لا يطابق عملية الاسترجاع.');
            }
            if ($release && $db->table('runtime_write_gate')->where('id', 1)->where('version', $version)->where('restore_job_id', $jobId)
                ->update(['blocked' => false, 'restore_job_id' => null, 'version' => $version + 1, 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('تعذر تحرير بوابة الكتابة بصورة محفوظة.');
            }
        });
    }

    private function assertNoUnresolvedDigital(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();
        if (($schema->hasTable('digital_provider_attempts') && $db->table('digital_provider_attempts')->whereNotNull('active_order_id')->exists())
            || ($schema->hasTable('digital_orders') && $db->table('digital_orders')->where(function (Builder $query): void {
                $query->where('reservation_active', true)->orWhereIn('status', ['pending', 'review']);
            })->exists())) {
            throw new RuntimeException('توجد عملية شراء رقمية غير محسومة؛ يجب التحقق من نتيجتها قبل استرجاع النظام.');
        }
    }

    public function beginMaintenance(string $jobId): array
    {
        return $this->exclusive($jobId, true, function (array $paths, $lock) use ($jobId): array {
            $connection = $this->configuration();
            $environment = $this->environment($paths['environment']);
            if (is_file($paths['maintenance']) || $environment['values']['DB_DATABASE'] !== $connection['database']) {
                throw new RuntimeException('النظام في صيانة قائمة أو إعداد قاعدة البيانات مختلف؛ أكمل المراجعة قبل الاسترجاع.');
            }
            $source = DB::connection('mysql');
            if ($source->getDatabaseName() !== $connection['database']) {
                throw new RuntimeException('اتصال المصدر لا يطابق ملف البيئة.');
            }
            $owner = ['job_id' => $jobId, 'phase' => 'starting', 'maintenance_hash' => null, 'target_gate_version' => null];
            $this->owner($lock, $owner);
            $rollback = null;
            $claimed = false;
            try {
                $rollback = $source->transaction(function () use ($source, $jobId, $paths, $environment): array {
                    $gate = $source->table('runtime_write_gate')->where('id', 1)->lockForUpdate()->first();
                    if (! $gate || $gate->blocked || $gate->restore_job_id !== null) {
                        throw new RuntimeException('عملية أخرى تتحكم ببوابة الكتابة.');
                    }
                    $this->assertNoUnresolvedDigital($source);
                    $backup = $paths['folder'].'/'.$jobId.'.env.before';
                    $this->write($backup, $environment['content']);
                    $state = ['job_id' => $jobId, 'environment' => $paths['environment'], 'backup' => $backup, 'source_database' => $source->getDatabaseName(),
                        'target_database' => $this->identifier($jobId), 'source_hash' => hash('sha256', $environment['content']), 'source_gate_version' => (int) $gate->version + 1];
                    $json = json_encode($state, JSON_THROW_ON_ERROR);
                    $this->write($paths['folder'].'/'.$jobId.'.json', $json);
                    $source->table('runtime_write_gate')->where('id', 1)->update(['blocked' => true, 'restore_job_id' => $jobId, 'version' => $state['source_gate_version'], 'updated_at' => now()]);

                    return ['job_id' => $jobId, 'state_hash' => hash('sha256', $json)];
                });
                $claimed = true;
                $this->artisan(['down', '--retry=30']);
                if (! is_file($paths['maintenance']) || is_link($paths['maintenance'])) {
                    throw new RuntimeException('لم يُثبت وضع الصيانة الخاص بالاسترجاع.');
                }
                $owner['phase'] = 'maintenance';
                $owner['maintenance_hash'] = hash_file('sha256', $paths['maintenance']);
                $this->owner($lock, $owner);

                return ['rollback' => $rollback];
            } catch (Throwable $failure) {
                if (! $claimed && is_file($paths['folder'].'/'.$jobId.'.json')) {
                    try {
                        $gate = $source->table('runtime_write_gate')->where('id', 1)->first();
                        if ($gate && $gate->blocked && $gate->restore_job_id === $jobId) {
                            $rollback = $this->rollbackFor($jobId);
                            $claimed = true;
                        } elseif (! $gate || $gate->blocked || $gate->restore_job_id !== null) {
                            throw new RuntimeException('تعذر تأكيد ملكية بوابة الكتابة بعد فشل تثبيت العملية.');
                        }
                    } catch (Throwable) {
                        throw new RuntimeException('تعذر تأكيد بوابة الكتابة؛ بقي قفل العملية محفوظًا للمراجعة دون رفع الصيانة.');
                    }
                }
                if ($claimed && $rollback !== null) {
                    if (is_file($paths['maintenance']) && ! is_link($paths['maintenance'])) {
                        $owner['maintenance_hash'] = hash_file('sha256', $paths['maintenance']);
                        $this->owner($lock, $owner);
                    }
                    $this->rollbackLocked($rollback, $paths, $lock, $owner);
                } else {
                    $this->owner($lock, null);
                }
                throw $failure;
            }
        });
    }

    private function state(array $rollback): array
    {
        $paths = $this->paths();
        $jobId = $this->job($rollback['job_id'] ?? '');
        $file = $paths['folder'].'/'.$jobId.'.json';
        if (! is_file($file) || is_link($file) || ! is_string($rollback['state_hash'] ?? null)) {
            throw new RuntimeException('ملف الرجوع غير موجود أو غير صالح.');
        }
        $json = file_get_contents($file);
        if (! hash_equals($rollback['state_hash'], hash('sha256', $json))) {
            throw new RuntimeException('ملف الرجوع لا يطابق بصمته.');
        }
        $state = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (($state['job_id'] ?? null) !== $jobId || ($state['environment'] ?? null) !== $paths['environment']
            || ($state['backup'] ?? null) !== $paths['folder'].'/'.$jobId.'.env.before' || is_link($state['backup']) || ! is_file($state['backup'])
            || ($state['target_database'] ?? null) !== $this->identifier($jobId) || ! is_int($state['source_gate_version'] ?? null) || $state['source_gate_version'] < 2
            || ! hash_equals($state['source_hash'] ?? '', hash_file('sha256', $state['backup']))) {
            throw new RuntimeException('ملف البيئة المحمي غير صالح للرجوع.');
        }
        $before = $this->environment($state['backup']);
        if (($state['source_database'] ?? null) !== $before['values']['DB_DATABASE']
            || ! preg_match('/^[a-z0-9_]{1,64}$/D', $state['source_database']) || ! str_starts_with($state['source_database'], config('backups.cpanel_user').'_')) {
            throw new RuntimeException('قاعدة المصدر المحمية لا تطابق سجل الرجوع.');
        }

        return $state;
    }

    private function rollbackFor(string $jobId): array
    {
        $path = $this->paths()['folder'].'/'.$this->job($jobId).'.json';
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('لا يوجد سجل محفوظ لهذه العملية.');
        }

        return ['job_id' => $jobId, 'state_hash' => hash_file('sha256', $path)];
    }

    private function replaceEnvironment(string $content, string $jobId): void
    {
        $environment = $this->paths()['environment'];
        $next = $environment.'.restore-'.$this->job($jobId);
        $this->write($next, $content);
        if (! rename($next, $environment)) {
            throw new RuntimeException('تعذر تبديل ملف البيئة بصورة ذرية.');
        }
    }

    private function selectDatabase(string $database): void
    {
        config(['database.connections.mysql.database' => $database, 'database.connections.mysql.url' => null]);
        DB::purge('mysql');
        $this->artisan(['config:cache']);
    }

    private function health(string $database, string $jobId, int $gateVersion): void
    {
        $nonce = bin2hex(random_bytes(16));
        $script = <<<'PHP'
try {
    require $argv[1].'/vendor/autoload.php';
    $app = require $argv[1].'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = \Illuminate\Support\Facades\DB::connection('mysql');
    if (! $app->configurationIsCached() || config('database.default') !== 'mysql' || ! empty(config('database.connections.mysql.url')) || $db->selectOne('SELECT DATABASE() AS name')->name !== $argv[2] || ! $app->isDownForMaintenance()) { exit(2); }
    $required = array_map(fn ($file) => pathinfo($file, PATHINFO_FILENAME), glob($argv[1].'/database/migrations/*.php'));
    $migrated = $db->table('migrations')->pluck('migration')->all();
    sort($required); sort($migrated);
    if ($required === [] || $required !== $migrated) { exit(3); }
    $owner = \App\Models\User::where('status', 'active')->whereHas('membership', fn ($query) => $query->where('kind', 'owner')->where('status', 'active')->whereHas('account', fn ($accounts) => $accounts->where('type', 'system')->where('status', 'active')->whereNull('archived_at')))->first();
    if (! $owner || ! $owner->isOperational()) { exit(4); }
    $gate = $db->table('runtime_write_gate')->where('id', 1)->first();
    if (! $gate || ! $gate->blocked || $gate->restore_job_id !== $argv[3] || (int) $gate->version !== (int) $argv[4]) { exit(5); }
    echo json_encode(['ready' => true, 'nonce' => $argv[5]], JSON_THROW_ON_ERROR);
} catch (\Throwable) { exit(6); }
PHP;
        $result = Process::path(base_path())->env($this->childEnvironment())->timeout(60)
            ->run([config('backups.php_binary'), '-r', $script, base_path(), $database, $jobId, (string) $gateVersion, $nonce]);
        $payload = json_decode($result->output(), true);
        if (! $result->successful() || ($payload['ready'] ?? null) !== true || ! hash_equals($nonce, $payload['nonce'] ?? '')) {
            throw new RuntimeException('فشل فحص التطبيق بعملية PHP جديدة؛ لم تُفتح الكتابة أو تُرفع الصيانة.');
        }
    }

    /** @param resource $lock */
    private function maintenance(array $paths, $lock, array $owner): array
    {
        if (is_file($paths['maintenance'])) {
            if (is_link($paths['maintenance']) || ($owner['maintenance_hash'] !== null && ! hash_equals($owner['maintenance_hash'], hash_file('sha256', $paths['maintenance'])))) {
                throw new RuntimeException('تغيرت الصيانة خارج العملية؛ يُمنع رفعها تلقائيًا.');
            }
        } else {
            $this->artisan(['down', '--retry=30']);
            if (! is_file($paths['maintenance']) || is_link($paths['maintenance'])) {
                throw new RuntimeException('تعذر تثبيت الصيانة قبل الرجوع.');
            }
            $owner['maintenance_hash'] = hash_file('sha256', $paths['maintenance']);
            $this->owner($lock, $owner);
        }

        return $owner;
    }

    public function switchVerified(string $jobId, array $scratch): array
    {
        return $this->exclusive($jobId, false, function (array $paths, $lock, array $owner) use ($jobId, $scratch): array {
            $rollback = $this->rollbackFor($jobId);
            $state = $this->state($rollback);
            $connection = $this->configuration();
            $expected = array_replace($connection, ['database' => $state['target_database'], 'url' => null]);
            if ($owner['phase'] !== 'maintenance' || ($scratch['identifier'] ?? null) !== $state['target_database'] || ($scratch['connection'] ?? null) !== $expected
                || ! hash_equals($state['source_hash'], hash_file('sha256', $paths['environment']))) {
                throw new RuntimeException('قاعدة الاسترجاع أو البيئة أو مرحلة العملية لا تطابق الفحص المحفوظ.');
            }
            $this->maintenance($paths, $lock, $owner);
            $this->gate(DB::connection('mysql'), $jobId, $state['source_gate_version'], false);
            $alias = 'cpanel_verified_'.str_replace('-', '', $jobId);
            config(['database.connections.'.$alias => $expected]);
            try {
                $target = DB::connection($alias);
                $owner['target_gate_version'] = $target->transaction(function () use ($target, $jobId): int {
                    $gate = $target->table('runtime_write_gate')->where('id', 1)->lockForUpdate()->first();
                    if (! $gate || $gate->blocked || $gate->restore_job_id !== null) {
                        throw new RuntimeException('قاعدة الاسترجاع ليست جاهزة لامتلاك بوابة الكتابة.');
                    }
                    $this->assertNoUnresolvedDigital($target);
                    $version = (int) $gate->version + 1;
                    $target->table('runtime_write_gate')->where('id', 1)->update(['blocked' => true, 'restore_job_id' => $jobId, 'version' => $version, 'updated_at' => now()]);

                    return $version;
                });
            } finally {
                DB::purge($alias);
                config(['database.connections.'.$alias => null]);
            }
            $owner['phase'] = 'switching';
            $this->owner($lock, $owner);
            $updated = preg_replace('/^DB_DATABASE=.*$/m', 'DB_DATABASE='.$state['target_database'], file_get_contents($state['backup']));
            $this->replaceEnvironment($updated, $jobId);
            $this->selectDatabase($state['target_database']);
            $this->health($state['target_database'], $jobId, $owner['target_gate_version']);
            $owner['phase'] = 'switched';
            $this->owner($lock, $owner);

            return ['rollback' => $rollback];
        });
    }

    public function finishMaintenance(string $jobId): void
    {
        $this->exclusive($jobId, false, function (array $paths, $lock, array $owner) use ($jobId): void {
            $state = $this->state($this->rollbackFor($jobId));
            if ($owner['phase'] !== 'switched' || DB::connection('mysql')->getDatabaseName() !== $state['target_database']) {
                throw new RuntimeException('لم تُفعّل قاعدة الاسترجاع المتحقق منها.');
            }
            $this->maintenance($paths, $lock, $owner);
            $this->gate(DB::connection('mysql'), $jobId, $owner['target_gate_version'], false);
            $this->health($state['target_database'], $jobId, $owner['target_gate_version']);
            $owner['phase'] = 'finishing';
            $this->owner($lock, $owner);
            $this->artisan(['up']);
            if (is_file($paths['maintenance'])) {
                throw new RuntimeException('لم يُرفع وضع الصيانة؛ تبقى الكتابة مقفلة.');
            }
            $this->gate(DB::connection('mysql'), $jobId, $owner['target_gate_version'], true);
            $this->owner($lock, null);
        });
    }

    /** @param resource $lock */
    private function rollbackLocked(array $rollback, array $paths, $lock, array $owner): void
    {
        $state = $this->state($rollback);
        $original = file_get_contents($state['backup']);
        $current = file_get_contents($paths['environment']);
        $target = preg_replace('/^DB_DATABASE=.*$/m', 'DB_DATABASE='.$state['target_database'], $original);
        if ($current !== $original && $current !== $target) {
            throw new RuntimeException('تغيرت البيئة خارج العملية؛ أُوقف الرجوع الآلي لحماية الإعدادات.');
        }
        $this->maintenance($paths, $lock, $owner);
        if ($current !== $original) {
            $this->replaceEnvironment($original, $state['job_id']);
        }
        $this->selectDatabase($state['source_database']);
        $source = DB::connection('mysql');
        $this->gate($source, $state['job_id'], $state['source_gate_version'], false);
        $this->health($state['source_database'], $state['job_id'], $state['source_gate_version']);
        $this->artisan(['up']);
        if (is_file($paths['maintenance'])) {
            throw new RuntimeException('لم يُرفع وضع الصيانة بعد الرجوع؛ تبقى الكتابة مقفلة.');
        }
        $this->gate($source, $state['job_id'], $state['source_gate_version'], true);
        $this->owner($lock, null);
    }

    public function rollbackSwitch(array $rollback): void
    {
        $this->exclusive($rollback['job_id'] ?? '', false, function (array $paths, $lock, array $owner) use ($rollback): void {
            $this->rollbackLocked($rollback, $paths, $lock, $owner);
        });
    }
}
