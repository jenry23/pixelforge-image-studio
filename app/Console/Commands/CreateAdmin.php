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

    /**
     * Read non-interactively from the environment rather than from an option,
     * so the password never appears in a command line, a deploy log or shell
     * history. Hosted runners (Laravel Cloud among them) have no TTY.
     */
    private const PASSWORD_ENV = 'ADMIN_INITIAL_PASSWORD';

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

        if ($this->input->isInteractive()) {
            // Typed twice and never echoed, so it is never in shell history,
            // scrollback, or a seeder.
            $password = $this->secret('Password');

            if ($password !== $this->secret('Confirm password')) {
                $this->error('The passwords did not match.');

                return self::FAILURE;
            }
        } else {
            $password = (string) env(self::PASSWORD_ENV, '');

            if ($password === '') {
                $this->error('No TTY, so the password must come from the environment.');
                $this->newLine();
                $this->line('Set '.self::PASSWORD_ENV.' in the host environment, run this command,');
                $this->line('then remove the variable again. For example on Laravel Cloud:');
                $this->newLine();
                $this->line('  1. add '.self::PASSWORD_ENV.' to the environment and redeploy');
                $this->line('  2. php artisan pixelforge:create-admin --name="…" --email="…"');
                $this->line('  3. delete '.self::PASSWORD_ENV.' and redeploy');

                return self::FAILURE;
            }
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

        if (! $this->input->isInteractive()) {
            $this->warn('Remove '.self::PASSWORD_ENV.' from the environment now that the account exists.');
        }

        $this->line('No starting credit was granted. Admins see the billing report;');
        $this->line('they do not need credit unless they also generate images.');

        return self::SUCCESS;
    }
}
