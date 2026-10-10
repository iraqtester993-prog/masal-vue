<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $ids = DB::table('topup_categories')->whereNull('connection_id')->pluck('id')->all();
            DB::table('topup_categories')->whereIn('id', $ids)->update(['active' => false, 'version' => DB::raw('version + 1')]);
            DB::table('digital_offers')->whereIn('topup_category_id', $ids)->orWhereNotNull('manual_category_version')->update(['active' => false, 'version' => DB::raw('version + 1')]);
            DB::table('topup_grants')->orderBy('id')->chunkById(100, function ($grants) use ($ids): void {
                foreach ($grants as $grant) {
                    $previous = json_decode($grant->category_ids, true);
                    $current = array_values(array_diff($previous, $ids));
                    if ($current !== $previous) {
                        DB::table('topup_grants')->where('id', $grant->id)->update(['category_ids' => json_encode($current), 'version' => $grant->version + 1]);
                    }
                }
            });
        });
    }

    public function down(): void
    {
        // Retired manual categories require deliberate review before any reactivation.
    }
};
