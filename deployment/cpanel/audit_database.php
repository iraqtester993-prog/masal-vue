<?php

declare(strict_types=1);

function requireAudit(bool $condition, string $code): void
{
    if (! $condition) {
        throw new RuntimeException($code);
    }
}

function quoteAuditIdentifier(string $name): string
{
    requireAudit((bool) preg_match('/\A[A-Za-z0-9_]+\z/D', $name), 'INVALID_DATABASE_IDENTIFIER');

    return '`'.$name.'`';
}

try {
    requireAudit(PHP_SAPI === 'cli', 'CLI_ONLY');
    $options = getopt('', ['backend-root:', 'expected-release:', 'expected-database:', 'config-sha256:']);
    $root = rtrim((string) ($options['backend-root'] ?? ''), '/');
    $release = (string) ($options['expected-release'] ?? '');
    $expectedDatabase = (string) ($options['expected-database'] ?? '');
    $configPin = (string) ($options['config-sha256'] ?? '');
    requireAudit($root !== '' && str_starts_with($root, '/') && realpath($root) === $root && ! is_link($root), 'BACKEND_ROOT_REQUIRED');
    requireAudit((bool) preg_match('/\A[a-z0-9][a-z0-9-]{1,95}\z/D', $release), 'EXPECTED_RELEASE_REQUIRED');
    quoteAuditIdentifier($expectedDatabase);
    requireAudit((bool) preg_match('/\A[a-f0-9]{64}\z/D', $configPin), 'CONFIG_PIN_REQUIRED');
    $current = $root.'/current';
    $target = $root.'/releases/'.$release;
    requireAudit(is_link($current) && realpath($current) === $target && realpath($target) === $target && ! is_link($target), 'CURRENT_RELEASE_CHANGED');
    $locks = [];
    foreach ([$root.'/.local/deployment.lock', $root.'/shared/storage/framework/backup-coordination.lock'] as $lockPath) {
        requireAudit(is_file($lockPath) && ! is_link($lockPath), 'COORDINATION_LOCK_REQUIRED');
        $handle = fopen($lockPath, 'r');
        requireAudit($handle !== false && flock($handle, LOCK_SH | LOCK_NB), 'COORDINATION_LOCK_BUSY');
        $locks[] = $handle;
    }
    $configPath = $target.'/bootstrap/cache/config.php';
    requireAudit(is_file($configPath) && ! is_link($configPath) && hash_equals($configPin, hash_file('sha256', $configPath)), 'CONFIG_PIN_CHANGED');
    $config = require $configPath;
    requireAudit(is_array($config) && ($config['app']['env'] ?? '') === 'production', 'PRODUCTION_CONFIG_REQUIRED');
    requireAudit(($config['database']['default'] ?? '') === 'mysql', 'MYSQL_REQUIRED');
    $database = $config['database']['connections']['mysql'];
    requireAudit(($database['database'] ?? '') === $expectedDatabase, 'DATABASE_NAME_CHANGED');
    $dsn = 'mysql:host='.$database['host'].';port='.($database['port'] ?? 3306).';dbname='.$expectedDatabase.';charset=utf8mb4';
    $pdo = new PDO($dsn, $database['username'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $databases = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME NOT IN ('information_schema','performance_schema','sys','mysql') ORDER BY SCHEMA_NAME")->fetchAll(PDO::FETCH_COLUMN);
    requireAudit($databases === [$expectedDatabase], 'UNEXPECTED_VISIBLE_DATABASE');
    $schemaStatement = $pdo->prepare('SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME,ORDINAL_POSITION');
    $schemaStatement->execute([$expectedDatabase]);
    $columns = $schemaStatement->fetchAll(PDO::FETCH_ASSOC);
    $tableStatement = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
    $tableStatement->execute([$expectedDatabase]);
    $tables = $tableStatement->fetchAll(PDO::FETCH_COLUMN);
    $triggerStatement = $pdo->prepare('SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT,ACTION_TIMING FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=? ORDER BY TRIGGER_NAME');
    $triggerStatement->execute([$expectedDatabase]);
    $triggers = $triggerStatement->fetchAll(PDO::FETCH_ASSOC);
    $volatile = ['sessions', 'cache', 'cache_locks', 'user_presences', 'sales_device_sessions', 'managed_password_reset_requests'];
    $business = [];
    $definitions = [];
    foreach ($tables as $table) {
        $definition = array_values($pdo->query('SHOW CREATE TABLE '.quoteAuditIdentifier($table))->fetch(PDO::FETCH_ASSOC))[1];
        $definitions[$table] = preg_replace('/\bAUTO_INCREMENT=\d+\s*/', '', $definition);
        if (in_array($table, $volatile, true)) {
            continue;
        }
        $names = array_column(array_filter($columns, fn (array $column): bool => $column['TABLE_NAME'] === $table), 'COLUMN_NAME');
        $order = implode(',', array_map(quoteAuditIdentifier(...), $names));
        $rows = $pdo->query('SELECT * FROM '.quoteAuditIdentifier($table).' ORDER BY '.$order);
        $digest = hash_init('sha256');
        $count = 0;
        while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
            hash_update($digest, serialize($row));
            $count++;
        }
        $business[$table] = ['rows' => $count, 'sha256' => hash_final($digest)];
    }
    $checks = [];
    foreach ([
        'wallet_ledger_mismatches' => 'SELECT COUNT(*) FROM finance_wallets w LEFT JOIN (SELECT wallet_id,SUM(amount_minor) total FROM finance_entries GROUP BY wallet_id) e ON e.wallet_id=w.id WHERE w.balance_minor<>COALESCE(e.total,0)',
        'unbalanced_transactions' => 'SELECT COUNT(*) FROM (SELECT transaction_id FROM finance_entries GROUP BY transaction_id HAVING SUM(amount_minor)<>0) t',
        'wallet_below_holds' => "SELECT COUNT(*) FROM finance_wallets WHERE kind='account' AND balance_minor<held_minor",
        'unposted_transactions' => 'SELECT COUNT(*) FROM finance_transactions WHERE posted=0',
    ] as $name => $query) {
        $checks[$name] = (int) $pdo->query($query)->fetchColumn();
        requireAudit($checks[$name] === 0, 'FINANCIAL_INVARIANT_FAILED');
    }
    $pdo->rollBack();
    echo json_encode([
        'passed' => true,
        'database' => $expectedDatabase,
        'release' => $release,
        'tables' => count($tables),
        'triggers' => count($triggers),
        'schema_sha256' => hash('sha256', serialize([$definitions, $triggers])),
        'business_sha256' => hash('sha256', json_encode($business, JSON_THROW_ON_ERROR)),
        'financial_checks' => $checks,
        'database_writes' => 0,
        'visible_databases_verified' => true,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'DATABASE_AUDIT_FAILED: '.($error instanceof RuntimeException && ! $error instanceof PDOException ? $error->getMessage() : get_class($error))."\n");
    exit(1);
}
