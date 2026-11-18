<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\LedgerWriter;
use App\Models\User;
use App\Pixelforge\Money;
use Illuminate\Auth\Events\Registered;

class GrantSignupCredit
{
    public function __construct(private readonly LedgerWriter $ledger) {}

    public function handle(Registered $event): void
    {
        $amount = Money::fromUsdString(
            (string) config('services.pixelforge.signup_credit_usd', '5.00')
        );

        if ($amount->isZero()) {
            return;
        }

        $this->ledger->forUser(
            $event->user->getKey(),
            fn (User $user) => $this->ledger->topup($user, $amount, 'Welcome credit')
        );
    }
}
