<?php

namespace Database\Factories\Support;

use App\Models\Support\SupportAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupportAttachment> */
class SupportAttachmentFactory extends Factory
{
    protected $model = SupportAttachment::class;

    public function definition(): array
    {
        return ['user_id' => User::factory(), 'kind' => 'support', 'path' => 'support/attachments/'.fake()->uuid().'.png', 'mime' => 'image/png', 'bytes' => 67, 'expires_at' => now()->addHour(), 'assigned' => false];
    }
}
