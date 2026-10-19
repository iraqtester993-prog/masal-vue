<?php

use App\Support\AccountPhone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $seen = [];
        $duplicates = [];
        DB::table('accounts')->orderBy('id')->chunkById(200, function ($rows) use (&$seen, &$duplicates): void {
            foreach ($rows as $row) {
                $key = AccountPhone::key($row->phone);
                if ($key === null) {
                    continue;
                }
                if (isset($seen[$key])) {
                    $duplicates[$key] ??= [$seen[$key]];
                    $duplicates[$key][] = $row->id;
                } else {
                    $seen[$key] = $row->id;
                }
            }
        });
        if ($duplicates !== []) {
            $groups = array_map(fn (array $ids): string => '['.implode(', ', $ids).']', array_values($duplicates));
            throw new RuntimeException('Cannot enforce unique account phones. Duplicate account IDs: '.implode('; ', $groups).'. Resolve these duplicates before retrying. No phone values or keys were changed.');
        }

        DB::transaction(function (): void {
            DB::table('accounts')->orderBy('id')->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('accounts')->where('id', $row->id)->update(['phone_key' => AccountPhone::key($row->phone)]);
                }
            });
        });
        Schema::table('accounts', function (Blueprint $table): void {
            $table->unique('phone_key', AccountPhone::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropUnique(AccountPhone::UNIQUE_INDEX);
        });
    }
};
