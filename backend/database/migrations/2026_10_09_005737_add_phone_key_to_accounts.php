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
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('phone_key', 30)->nullable()->index();
        });
        DB::table('accounts')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('accounts')->where('id', $row->id)->update(['phone_key' => $row->type === 'system' ? null : AccountPhone::key($row->phone)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropIndex(['phone_key']);
            $table->dropColumn('phone_key');
        });
    }
};
