<?php

namespace Database\Factories\Support;

use App\Models\Support\SupportMessage;
use App\Models\Support\SupportTicket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupportMessage> */
class SupportMessageFactory extends Factory
{
    protected $model = SupportMessage::class;

    public function definition(): array
    {
        return ['ticket_id' => SupportTicket::factory(), 'user_id' => fn (array $a) => SupportTicket::findOrFail($a['ticket_id'])->sender_id, 'account_id' => fn (array $a) => SupportTicket::findOrFail($a['ticket_id'])->origin_id, 'body' => fake()->paragraph(), 'is_opening' => false];
    }
}
