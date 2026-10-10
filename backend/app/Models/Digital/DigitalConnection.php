<?php

namespace App\Models\Digital;

use App\Models\Account;
use App\Models\CatalogProvider;
use App\Services\Digital\DigitalConfiguration;
use Database\Factories\Digital\DigitalConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DigitalConnection extends Model
{
    /** @use HasFactory<DigitalConnectionFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['credential', 'topup_credential_hash'];

    protected static function booted(): void
    {
        static::saving(function (DigitalConnection $connection): void {
            $credential = trim((string) $connection->credential);
            $connection->topup_credential_hash = $connection->provider === 'topup' && $credential !== '' ? DigitalConfiguration::credentialHash($credential) : null;
        });
    }

    protected function casts(): array
    {
        return ['credential' => 'encrypted', 'active' => 'boolean', 'version' => 'integer', 'company_balance_minor' => 'integer', 'balance_updated_at' => 'datetime', 'bein_provinces' => 'array'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(CatalogProvider::class, 'provider_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(DigitalOffer::class, 'connection_id');
    }

    public function grants(): HasMany
    {
        return $this->hasMany(DigitalGrant::class, 'connection_id');
    }
}
