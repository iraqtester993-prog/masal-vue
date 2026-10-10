<?php

namespace App\Models\Preferences;

use Database\Factories\Preferences\UserPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserPreference extends Model
{
    /** @use HasFactory<UserPreferenceFactory> */
    use HasFactory;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['language', 'theme', 'version'];

    protected $hidden = ['user_id'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
