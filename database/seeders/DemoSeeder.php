<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\LedgerWriter;
use App\Models\User;
use App\Pixelforge\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $ledger = app(LedgerWriter::class);

        foreach ([
            ['name' => 'Operator', 'email' => 'admin@example.com', 'is_admin' => true, 'credit' => '20.00'],
            ['name' => 'Sam Rivera', 'email' => 'user@example.com', 'is_admin' => false, 'credit' => '5.00'],

            ['name' => 'Skint Account', 'email' => 'broke@example.com', 'is_admin' => false, 'credit' => null],
        ] as $spec) {
            $user = User::query()->firstOrCreate(
                ['email' => $spec['email']],
                [
                    'name' => $spec['name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'is_admin' => $spec['is_admin'],
                ],
            );

            if ($spec['credit'] === null) {
                $user->creditTransactions()->delete();
                $user->forceFill(['balance_nanodollars' => 0, 'held_nanodollars' => 0])->save();
            }

            if ($spec['credit'] !== null && $user->balance()->isZero()) {
                $ledger->forUser($user->getKey(), fn (User $locked) => $ledger->topup(
                    $locked,
                    Money::fromUsdString($spec['credit']),
                    'Seeded demo credit',
                ));
            }

            $this->command->info("{$spec['email']} / password".($spec['is_admin'] ? ' (admin)' : ''));
        }
    }
}
