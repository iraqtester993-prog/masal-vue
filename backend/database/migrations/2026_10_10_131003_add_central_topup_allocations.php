<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('digital_connections', function (Blueprint $table): void {
            $table->dropUnique('digital_connection_identity');
            $table->index(['account_id', 'provider'], 'digital_connection_owner');
        });
        Schema::table('topup_grants', function (Blueprint $table): void {
            $table->foreignId('connection_id')->nullable()->constrained('digital_connections')->restrictOnDelete();
            $table->unsignedBigInteger('balance_minor')->default(0);
            $table->unsignedBigInteger('held_minor')->default(0);
            $table->unsignedBigInteger('spent_minor')->default(0);
            $table->json('retail_prices')->nullable();
        });
        Schema::table('digital_orders', function (Blueprint $table): void {
            $table->foreignId('topup_grant_id')->nullable()->constrained('topup_grants')->restrictOnDelete();
            $table->unsignedBigInteger('admin_price_minor')->nullable();
            $table->timestamp('accounted_at')->nullable();
        });
        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->foreignId('creditor_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
        });
        // Existing tokens retain their IDs and order history. Their known remaining balance
        // becomes the original agent's allocation; an unknown balance is never invented.
        foreach (\Illuminate\Support\Facades\DB::table('digital_connections')->where('provider', 'topup')->get() as $connection) {
            $owner = \Illuminate\Support\Facades\DB::table('accounts')->find($connection->account_id);
            if ($owner?->type !== 'main_agent') {
                continue;
            }
            $db = \Illuminate\Support\Facades\DB::class;
            $grant = $db::table('topup_grants')->where('target_account_id', $owner->id)->first();
            $db::table('topup_grants')->updateOrInsert(['target_account_id' => $owner->id], [
                'main_account_id' => $owner->id, 'from_account_id' => $owner->parent_id,
                'connection_id' => $connection->id, 'category_ids' => $grant?->category_ids ?? '[]',
                'active' => $grant?->active ?? false, 'version' => ($grant?->version ?? 0) + 1,
                'balance_minor' => $connection->company_balance_minor ?? 0,
                'created_at' => $grant?->created_at ?? now(), 'updated_at' => now(),
            ]);
            $db::table('digital_connections')->where('id', $connection->id)->update(['account_id' => $owner->parent_id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Central Topup accounting requires restoring the pre-migration backup; financial allocations cannot be discarded.');
    }
};
