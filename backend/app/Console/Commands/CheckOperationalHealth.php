<?php

namespace App\Console\Commands;

use App\Models\Backups\ServerBackup;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

#[Signature('system:health-check {--notify : Notify active system owners of operational warnings}')]
#[Description('Check backup freshness, storage, unresolved provider operations, restore failures and accounting')]
class CheckOperationalHealth extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(NotificationService $notices): int
    {
        $warnings = [];
        $latest = ServerBackup::where('status', 'completed')->latest()->first();
        if (! $latest || $latest->created_at->lt(now()->subHours(26)) || ! Storage::disk('local')->exists($latest->path) || ! hash_equals($latest->sha256, hash_file('sha256', Storage::disk('local')->path($latest->path)))) {
            $warnings['backup'] = 'لا توجد نسخة احتياطية سليمة وحديثة خلال آخر 26 ساعة.';
        }
        $free = disk_free_space(storage_path());
        if ($free === false || $free < 512 * 1024 * 1024) {
            $warnings['storage'] = 'مساحة التخزين المتاحة أقل من 512 ميغابايت أو تعذر فحصها.';
        }
        $pending = DB::table('digital_orders')->whereIn('status', ['pending', 'review'])->where('created_at', '<', now()->subMinutes(10))->count();
        if ($pending > 0) {
            $warnings['provider_pending'] = 'توجد عمليات شركة معلقة لأكثر من 10 دقائق: '.$pending.'. راجع الطلب الأصلي دون إعادة شراء.';
        }
        $restore = DB::table('restore_jobs')->whereIn('status', ['failed', 'rollback_failed'])->where('updated_at', '>', now()->subDay())->count();
        if ($restore > 0) {
            $warnings['restore'] = 'توجد عمليات استعادة فاشلة خلال آخر يوم: '.$restore.'.';
        }
        if ($this->callSilent('topup:audit-accounting') !== self::SUCCESS) {
            $warnings['accounting'] = 'فشلت مطابقة Topup المالية؛ راجع سجل المطابقة الخاص بالسيرفر.';
        }
        if ($warnings !== []) {
            Log::warning('Operational health warnings.', ['codes' => array_keys($warnings)]);
            if ($this->option('notify')) {
                $owners = User::with('membership.account')->where('status', 'active')->whereHas('membership', fn ($query) => $query->where('kind', 'owner')->where('status', 'active')->whereHas('account', fn ($account) => $account->where('type', 'system')->where('status', 'active')->whereNull('archived_at')))->get()->filter(fn (User $user): bool => $user->isOperational() && in_array('notifications.view', $user->membership->permissions(), true))->pluck('id')->all();
                DB::transaction(fn () => $notices->create(null, 'تنبيه متابعة النظام', implode("\n", $warnings), $owners, ['event_key' => 'health:'.now()->format('Y-m-d').':'.hash('sha256', implode('|', array_keys($warnings))), 'translations' => [
                    'en' => ['title' => 'System maintenance alert', 'body' => 'System checks require attention. Review the protected operational health report. Warning count: '.count($warnings)],
                    'ckb' => ['title' => 'ئاگادارکردنەوەی چاودێری سیستەم', 'body' => 'پشکنینەکانی سیستەم پێویستیان بە پێداچوونەوە هەیە. ڕاپۆرتی چاودێری سیستەم بپشکنە. ژمارەی ئاگادارکردنەوەکان: '.count($warnings)],
                ]]));
            }
        }
        $this->line(json_encode(['checked_at' => now()->toIso8601String(), 'ok' => $warnings === [], 'warnings' => $warnings, 'backup_id' => $latest?->id, 'free_storage_bytes' => $free], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $warnings === [] ? self::SUCCESS : self::FAILURE;
    }
}
