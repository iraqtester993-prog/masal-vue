<?php

namespace App\Console\Commands;

use App\Models\Digital\CatalogSnapshot;
use App\Models\Digital\DigitalConnection;
use App\Services\Digital\DigitalConfiguration;
use App\Services\Digital\ProviderGateway;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('topup:refresh-excluded-catalog')]
#[Description('Fetch missing exclusion details without changing prices, grants or balances')]
class RefreshTopupExcludedCatalog extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ProviderGateway $gateway): int
    {
        $updated = 0;
        $skipped = 0;
        foreach (DigitalConnection::where('provider', 'topup')->whereNotNull('credential')->cursor() as $connection) {
            $hash = DigitalConfiguration::credentialHash($connection->credential);
            $snapshot = CatalogSnapshot::where('connection_id', $connection->id)->where('credential_hash', $hash)->latest('id')->first();
            if (! $snapshot || $snapshot->excluded_catalog !== null || ! $gateway->directTopup($connection)) {
                continue;
            }
            try {
                $result = $gateway->call($connection, 'catalog');
                if ($this->signature($snapshot->catalog) !== $this->signature($result['catalog'])) {
                    $skipped++;

                    continue;
                }
                $saved = DB::transaction(function () use ($connection, $snapshot, $hash, $result): bool {
                    $current = DigitalConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
                    $latest = CatalogSnapshot::where('connection_id', $current->id)->where('credential_hash', DigitalConfiguration::credentialHash($current->credential))->latest('id')->lockForUpdate()->first();
                    if (! hash_equals($hash, DigitalConfiguration::credentialHash($current->credential)) || $latest?->id !== $snapshot->id || $latest->excluded_catalog !== null) {
                        return false;
                    }
                    DB::table('digital_catalog_exclusions')->insert(['snapshot_id' => $latest->id, 'excluded_catalog' => json_encode($result['excluded_catalog'], JSON_THROW_ON_ERROR), 'created_at' => now()]);

                    return true;
                });
                $saved ? $updated++ : $skipped++;
            } catch (\Throwable) {
                $skipped++;
            }
        }
        $this->line(json_encode(['updated' => $updated, 'requires_catalog_refresh' => $skipped], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function signature(array $catalog): array
    {
        return collect($catalog)->map(fn (array $row): array => ['remote_id' => $row['remote_id'], 'province_id' => $row['province_id'], 'type' => $row['type'], 'remote_name' => $row['remote_name'], 'cost' => $row['cost']])->sortBy(fn (array $row): string => $row['remote_id'].':'.$row['province_id'])->values()->all();
    }
}
