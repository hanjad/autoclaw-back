<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

// Creates the very first admin. After that, admins are invited from the dashboard.
// Usage: php artisan app:create-admin you@example.com --password="a-long-password"
// Or set INITIAL_ADMIN_EMAIL / INITIAL_ADMIN_PASSWORD (and optionally INITIAL_ADMIN_FIRSTNAME,
// INITIAL_ADMIN_SURNAME, INITIAL_ADMIN_PHONE) and run it without arguments.
class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {email?} {--password=} {--firstname=} {--surname=} {--phone=}';

    protected $description = 'Create the first admin account';

    public function handle(): int
    {
        $email = strtolower((string) ($this->argument('email') ?: config('admin.initial_email')));
        $password = (string) ($this->option('password') ?: config('admin.initial_password'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Give a valid email address.');
            return self::FAILURE;
        }

        if (strlen($password) < 12) {
            $this->error('The password must be at least 12 characters.');
            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->info("A user with {$email} already exists. Nothing changed.");
            return self::SUCCESS;
        }

        $user = new User();
        $user->firstname = $this->option('firstname') ?: config('admin.initial_firstname');
        $user->surname = $this->option('surname') ?: config('admin.initial_surname');
        $user->email = $email;
        $user->phone = $this->option('phone') ?: config('admin.initial_phone');
        $user->password = $password;
        $user->role = 'admin';
        $user->email_verified_at = now();
        $user->save();

        $this->info("Admin created: {$email}");

        return self::SUCCESS;
    }
}
