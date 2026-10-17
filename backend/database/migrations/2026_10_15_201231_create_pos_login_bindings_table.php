<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pos_login_bindings', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->primary();
            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $table->unsignedBigInteger('registered_device_id')->nullable();
            $table->foreign('registered_device_id')->references('id')->on('pos_registered_devices')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        DB::table('accounts')->where('type', 'pos')->orderBy('id')->chunkById(500, function ($accounts): void {
            $ids = $accounts->pluck('id')->all();
            $devices = DB::table('pos_registered_devices')->whereIn('account_id', $ids)->where('status', 'approved')->whereNull('archived_at')->whereNotNull('proof_public_key')->get()->groupBy('account_id');
            DB::table('pos_login_bindings')->insert(array_map(fn ($id): array => ['account_id' => $id,
                'registered_device_id' => ($devices->get($id)?->count() === 1) ? $devices->get($id)->first()->id : null,
                'version' => 1, 'created_at' => now(), 'updated_at' => now()], $ids));
            $users = DB::table('account_memberships')->whereIn('account_id', $ids)->pluck('user_id')->all();
            if ($users !== []) {
                DB::table('users')->whereIn('id', $users)->increment('session_version');
                DB::table('users')->whereIn('id', $users)->update(['remember_token' => null]);
                DB::table('sessions')->whereIn('user_id', $users)->delete();
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $users)->delete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_login_bindings');
    }
};
