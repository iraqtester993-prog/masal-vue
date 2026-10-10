<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'login', 'password', 'status'];

    protected $hidden = ['password', 'remember_token'];

    public function membership(): HasOne
    {
        return $this->hasOne(AccountMembership::class);
    }

    public function isOperational(): bool
    {
        return $this->status === 'active' && $this->membership && $this->membership->isOperational();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'session_version' => 'integer',
        ];
    }
}
