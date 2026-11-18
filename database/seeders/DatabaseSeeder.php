<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Deliberately nothing in a deployed environment: every account here
        // has a known password. Production gets its first admin from
        // `php artisan pixelforge:create-admin`, which prompts for one.
        if (! app()->environment(['local', 'testing'])) {
            $this->command->warn('Skipping seeders: they create accounts with known passwords.');
            $this->command->line('Create the first admin with `php artisan pixelforge:create-admin`.');

            return;
        }

        $this->call(DemoSeeder::class);
    }
}
