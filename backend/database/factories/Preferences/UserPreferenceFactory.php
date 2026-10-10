<?php

namespace Database\Factories\Preferences;

use App\Models\Preferences\UserPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserPreference> */
class UserPreferenceFactory extends Factory
{
    protected $model = UserPreference::class;

    public function definition(): array
    {
        return ['user_id' => User::factory(), 'language' => 'ar', 'theme' => 'light', 'version' => 1];
    }
}
