<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

/**
 * Creates the one owner account. There is no default user and no seeded
 * password: this is the only way in.
 */
class CreateOwner extends Command
{
    protected $signature = 'owner:create
        {email : The owner\'s email address, used to sign in}
        {--name= : Display name (defaults to the email)}
        {--password= : The password; prompted for when omitted}';

    protected $description = 'Create the single owner account';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->error('An owner already exists: this app has exactly one login.');

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');
        $name = $this->option('name');
        $name = is_string($name) && $name !== '' ? $name : $email;
        $password = $this->option('password');
        $password = is_string($password) && $password !== '' ? $password : password('Password', required: true);

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', Password::min(12)->uncompromised()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);

        $this->info("Owner {$email} created. Sign in at ".route('filament.admin.auth.login').' and set up two-factor authentication.');

        return self::SUCCESS;
    }
}
