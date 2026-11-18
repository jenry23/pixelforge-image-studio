<?php

// Puts one queued and one processing generation on the demo user, so the
// in-flight presentation can be asserted without waiting on a real provider.

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;

$user = User::query()->where('email', 'user@example.com')->sole();

Generation::query()
    ->where('user_id', $user->getKey())
    ->whereIn('status', ['pending', 'queued', 'processing'])
    ->delete();

foreach ([GenerationStatus::Queued, GenerationStatus::Processing] as $index => $status) {
    Generation::factory()->create([
        'user_id' => $user->getKey(),
        'status' => $status,
        'prompt' => "e2e in-flight fixture {$index}",
        'provider_id' => 'gen_E2EFIXTURE'.$index.str_repeat('0', 13),
        'image_url' => null,
        'usage' => null,
        'charged_nanodollars' => null,
        'provider_cost_nanodollars' => null,
        'settled_at' => null,
        'hold_nanodollars' => 86_250_000,
    ]);
}

echo "seeded in-flight fixtures\n";
