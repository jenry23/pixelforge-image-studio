<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'pixelforge:create-admin
        {--email= : Skip the prompt for the email address}
        {--name= : Skip the prompt for the display name}';

    protected $description = 'Create an admin account, prompting for the password rather than seeding a known one';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $email = $this->option('email') ?: $this->ask('Email');

        if (User::query()->where('email', $email)->exists()) {
            $this->error("A user already exists with [{$email}].");
            $this->line('Promote them instead, or use a different address.');

            return self::FAILURE;
        }

        // Typed twice and never echoed, so it is never in shell history,
        // scrollback, or a seeder.
        $password = $this->secret('Password');
        $confirmation = $this->secret('Confirm password');

        if ($password !== $confirmation) {
            $this->error('The passwords did not match.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:12'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // is_admin is deliberately not mass-assignable on the model, so that a
        // crafted registration payload can never grant itself the flag. Set it
        // here explicitly instead of widening $fillable.
        $user = new User;
        $user->name = $name;
        $user->email = $email;
        $user->password = Hash::make($password);
        $user->email_verified_at = now();
        $user->is_admin = true;
        $user->save();

        $this->info("Admin created: {$user->email}");
        $this->line('No starting credit was granted. Admins see the billing report;');
        $this->line('they do not need credit unless they also generate images.');

        return self::SUCCESS;
    }
}
