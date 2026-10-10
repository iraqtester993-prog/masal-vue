<?php

use App\Services\Backups\UnavailableRestorePlatform;

return [
    'platform_class' => env('BACKUP_PLATFORM_CLASS', UnavailableRestorePlatform::class),
    'runtime_root' => env('BACKUP_RUNTIME_ROOT', '/home/dananiriq/masal-backend'),
    'cpanel_user' => env('BACKUP_CPANEL_USER', 'dananiriq'),
    'uapi' => env('BACKUP_UAPI', '/usr/local/cpanel/bin/uapi'),
    'php_binary' => env('BACKUP_PHP_BINARY', PHP_BINARY),
    'upload_bytes' => 15 * 1024 * 1024,
    'record_bytes' => 32 * 1024 * 1024,
    'preview_hours' => 2,
    'excluded_tables' => ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'personal_access_tokens', 'sales_device_sessions', 'server_backups', 'backup_previews', 'restore_jobs', 'runtime_write_gate'],
];
