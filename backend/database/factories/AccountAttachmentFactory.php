<?php

namespace Database\Factories;

use App\Models\AccountAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountAttachment>
 */
class AccountAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => 'document',
            'document_type' => 'national_card',
            'storage_path' => 'accounts/attachments/'.fake()->uuid().'.png',
            'mime_type' => 'image/png',
            'bytes' => 100,
        ];
    }
}
