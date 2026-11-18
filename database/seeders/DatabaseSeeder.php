<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // The demo accounts are part of the deliverable: reviewers need to be
        // able to sign in and look around. They share one documented password,
        // so change it on anything that matters.
        $this->call(DemoSeeder::class);
    }
}
