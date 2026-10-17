<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\TopupGrant;
use App\Models\User;
use App\Services\Digital\TopupWallets;
use App\Services\Operations\MutationGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BootstrapTopupWallets extends Command
{
    protected $signature = 'topup:bootstrap-wallets {--actor= : Explicit system owner user ID} {--gate-owner= : Deployment ownership UUID}';

    protected $description = 'Initialize funded Topup wallets once under owned maintenance without changing existing allocations.';

    public function handle(TopupWallets $wallets): int
    {
        $owner = (string) $this->option('gate-owner');
        $actorId = (string) $this->option('actor');
        if (! Str::isUuid($owner) || ! ctype_digit($actorId) || (int) $actorId < 1 || ! app()->isDownForMaintenance()) {
            $this->error('Explicit system owner, owned write gate and maintenance are required.');

            return self::FAILURE;
        }
        $qa = app()->environment('testing') && DB::connection()->getDatabaseName() === 'dananiriq_masalverify';
        $root = $qa ? '/home/dananiriq/masal-verification' : '/home/dananiriq/masal-backend';
        $base = str_replace('\\', '/', realpath(base_path()) ?: '');
        $storage = str_replace('\\', '/', realpath(storage_path()) ?: '');
        $path = $root.'/.local/deploy/rollup-'.$owner.'.json';
        if ((! $qa && (! app()->environment('production') || DB::connection()->getDatabaseName() !== 'dananiriq_masalprod'))
            || DB::getDriverName() !== 'mysql' || ! str_starts_with($base, $root.'/') || ! str_starts_with($storage, $root.'/')
            || ! is_file($path) || is_link($path)) {
            $this->error('Deployment ownership or runtime location is invalid.');

            return self::FAILURE;
        }
        $state = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $down = storage_path('framework/down');
        $actor = User::findOrFail((int) $actorId);
        abort_unless($actor->isOperational() && $actor->membership->kind === 'owner' && $actor->membership->account->type === AccountType::System && $actor->membership->status === 'active', 403, 'اختر مستخدم مدير النظام المالك الفعال.');
        $assertOwned = function () use ($state, $owner, $down): void {
            $gate = DB::table('runtime_write_gate')->where('id', 1)->lockForUpdate()->first();
            abort_unless(($state['id'] ?? null) === $owner && ($state['phase'] ?? null) === 'closed' && ($state['database'] ?? null) === DB::connection()->getDatabaseName()
                && $gate && $gate->blocked && $gate->restore_job_id === null && (int) $gate->version === ($state['version'] ?? null)
                && is_file($down) && is_string($state['maintenance_hash'] ?? null) && hash_equals($state['maintenance_hash'], hash_file('sha256', $down)), 409,
                'Deployment maintenance ownership changed; no further wallets were initialized.');
        };
        DB::transaction(function () use ($assertOwned, $wallets): void {
            $assertOwned();
            $wallets->assertNoUnboundMoney();
        });
        $count = 0;
        TopupGrant::whereNotNull('connection_id')->whereColumn('target_account_id', 'main_account_id')->orderBy('id')->chunkById(100, function ($grants) use ($wallets, $actor, $assertOwned, &$count): void {
            foreach ($grants as $candidate) {
                DB::transaction(function () use ($candidate, $wallets, $actor, $assertOwned): void {
                    $assertOwned();
                    app(MutationGuard::class)->lockAccounts([$actor->membership->account_id, $candidate->target_account_id], $actor->membership->account_id, false);
                    DigitalConnection::whereKey($candidate->connection_id)->lockForUpdate()->firstOrFail();
                    $grant = TopupGrant::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                    $wallets->bootstrap($grant, $actor);
                }, 5);
                $count++;
            }
        });
        $this->line(json_encode(['initialized_grants' => $count, 'idempotent' => true, 'point_opening_balances' => 0, 'provider_calls' => 0], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
