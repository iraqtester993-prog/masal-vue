<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('support:prune-attachments')->hourly()->withoutOverlapping(30);
Schedule::command('backups:work')->everyMinute()->withoutOverlapping(90);
Schedule::command('backups:create-scheduled')->hourly()->withoutOverlapping(60);
Schedule::command('topup:audit-accounting')->hourly()->withoutOverlapping(30)->appendOutputTo(storage_path('logs/topup-accounting-audit.log'));
Schedule::command('system:health-check --notify')->hourlyAt(10)->withoutOverlapping(30)->appendOutputTo(storage_path('logs/operational-health.log'));
