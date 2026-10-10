<?php

use App\Models\Digital\DigitalConnection;
use App\Services\Digital\DigitalConfiguration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hashes = [];
        $owners = [];
        foreach (DigitalConnection::where('provider', 'topup')->orderBy('id')->get() as $connection) {
            $credential = trim((string) $connection->credential);
            if ($credential === '') {
                continue;
            }
            $hash = DigitalConfiguration::credentialHash($credential);
            if (isset($owners[$hash])) {
                throw new RuntimeException('Duplicate Topup token assignments must be resolved before migration.');
            }
            $owners[$hash] = $connection->id;
            $hashes[$connection->id] = $hash;
        }
        Schema::table('digital_connections', function (Blueprint $table): void {
            $table->char('topup_credential_hash', 64)->nullable()->unique();
        });
        foreach ($hashes as $id => $hash) {
            DB::table('digital_connections')->where('id', $id)->update(['topup_credential_hash' => $hash]);
        }
    }

    public function down(): void
    {
        Schema::table('digital_connections', function (Blueprint $table): void {
            $table->dropUnique(['topup_credential_hash']);
            $table->dropColumn('topup_credential_hash');
        });
    }
};
