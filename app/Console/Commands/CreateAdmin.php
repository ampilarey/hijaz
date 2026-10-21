<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Access;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * The first account, and the way back in if every Admin is lost.
 * Run in cPanel Terminal: php artisan portal:admin you@example.com 'a long password'
 */
class CreateAdmin extends Command
{
    protected $signature = 'portal:admin {email} {password} {--name=Admin : Display name for a new account} {--phone= : Mobile number, to sign in with as well}';

    protected $description = 'Create an Admin account, or give an existing account the Admin role';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $password = (string) $this->argument('password');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('That is not an email address.');

            return self::FAILURE;
        }

        if (mb_strlen($password) < 8) {
            $this->error('The password needs at least 8 characters.');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $created = ! $user->exists;

        $user->fill([
            'name' => $user->name ?: (string) $this->option('name'),
            'phone' => $this->option('phone') ? Phone::normalise((string) $this->option('phone')) : $user->phone,
            'password' => Hash::make($password),
            'is_active' => true,
        ]);
        $user->email_verified_at ??= now();
        $user->save();
        $user->assignRole(Access::ADMIN);

        $this->info(($created ? 'Created ' : 'Updated ').$email.' with the Admin role.');
        $this->line('Sign in at '.rtrim((string) config('app.url'), '/').'/portal and set up an authenticator app when asked.');

        return self::SUCCESS;
    }
}
