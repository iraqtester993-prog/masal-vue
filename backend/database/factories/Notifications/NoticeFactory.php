<?php

namespace Database\Factories\Notifications;

use App\Models\Notifications\Notice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** @extends Factory<Notice> */
class NoticeFactory extends Factory
{
    protected $model = Notice::class;

    public function definition(): array
    {
        return ['sender_id' => User::factory(), 'title' => fake()->sentence(3), 'body' => fake()->paragraph(), 'translations' => null];
    }

    public function toUser(User $user): static
    {
        return $this->afterCreating(function (Notice $notice) use ($user): void {
            DB::table('notice_recipients')->insert(['notice_id' => $notice->id, 'user_id' => $user->id, 'read_at' => null]);
        });
    }
}
