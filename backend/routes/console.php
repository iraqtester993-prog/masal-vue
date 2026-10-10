<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('support:prune-attachments')->hourly()->withoutOverlapping(30);
Schedule::command('backups:work')->everyMinute()->withoutOverlapping(90);
