<?php

namespace Database\Factories\Support;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Support\SupportMessage;
use App\Models\Support\SupportTicket;
use App\Models\User;
use App\Services\AccountHierarchy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** @extends Factory<SupportTicket> */
class SupportTicketFactory extends Factory
{
    protected $model = SupportTicket::class;

    public function definition(): array
    {
        return ['sender_id' => User::factory(), 'origin_id' => function (): int {
            $h = app(AccountHierarchy::class);
            $system = Account::where('type', AccountType::System)->first() ?? $h->create('Factory System', AccountType::System);

            return $h->create(fake()->company(), AccountType::MainAgent, $system)->id;
        }, 'recipient_id' => fn (array $a) => Account::findOrFail($a['origin_id'])->parent_id, 'title' => fake()->sentence(3), 'description' => fake()->paragraph(), 'status' => 'open', 'route_stack' => fn (array $a) => [(int) $a['origin_id'], (int) $a['recipient_id']], 'last_message_at' => now(), 'version' => 1];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (SupportTicket $ticket): void {
            DB::table('support_ticket_participants')->insert(array_map(fn ($id) => ['ticket_id' => $ticket->id, 'account_id' => $id], array_unique($ticket->route_stack)));
            SupportMessage::create(['ticket_id' => $ticket->id, 'user_id' => $ticket->sender_id, 'account_id' => $ticket->origin_id, 'is_opening' => true, 'body' => $ticket->description]);
        });
    }
}
