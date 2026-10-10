<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use App\Services\AccountHierarchy;
use Database\Seeders\FoundationPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateSystemAdmin extends Command
{
    protected $signature = 'masal:create-admin {--login=} {--email=} {--name=}';

    protected $description = 'Create an initial system administrator with an interactive hidden password.';

    public function handle(AccountHierarchy $hierarchy): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run interactively; passwords are never accepted as command arguments.');

            return self::FAILURE;
        }
        $attributes = [
            'login' => mb_strtolower(trim($this->option('login') ?? $this->ask('Login username'))),
            'email' => mb_strtolower(trim($this->option('email') ?? $this->ask('Email address'))),
            'name' => $this->option('name') ?? $this->ask('Administrator name'),
            'password' => $this->secret('Password (12+ characters, letters and numbers)'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];
        $validator = Validator::make($attributes, [
            'login' => ['required', 'string', 'regex:/^[a-z][a-z0-9._-]{2,63}$/', 'unique:users,login'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'name' => ['required', 'string', 'max:190'],
            'password' => ['required', 'confirmed', Password::min(9), function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('Password must not exceed 72 bytes.');
                }
            }],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        DB::transaction(function () use ($attributes, $hierarchy): void {
            (new FoundationPermissionsSeeder)->run();
            $account = Account::where('type', AccountType::System->value)->first()
                ?? $hierarchy->create('إدارة النظام', AccountType::System);
            if (! $account->isOperational()) {
                throw new \RuntimeException('The system account is disabled or its hierarchy is invalid.');
            }
            $user = User::create(collect($attributes)->except('password_confirmation')->all());
            AccountMembership::create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'role_id' => DB::table('roles')->where('name', 'admin')->value('id'),
                'status' => 'active',
            ]);
        });
        $this->info('Administrator created. No password has been printed or logged.');

        return self::SUCCESS;
    }
}
