<?php

namespace App\Models;

use Database\Factories\NetworkRepresentativeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkRepresentative extends Model
{
    /** @use HasFactory<NetworkRepresentativeFactory> */
    use HasFactory;

    protected $fillable = ['agent_account_id', 'name', 'phone', 'address', 'status', 'version'];

    protected $attributes = ['status' => 'active', 'version' => 1, 'name' => '', 'phone' => '', 'address' => ''];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'agent_account_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(RepresentativePhoto::class, 'representative_id')->orderBy('id');
    }
}
