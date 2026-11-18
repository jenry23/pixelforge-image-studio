<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\LedgerWriter;
use App\Models\User;
use App\Pixelforge\Money;
use Illuminate\Auth\Events\Registered;

/**
 * Gives a new account a starting balance, so the app is usable the moment you
 * sign in rather than dead until you press "Buy credits".
 *
 * Written through the ledger like any other top-up, so the grant is auditable
 * and the balance cache stays consistent -- there is deliberately no path that
 * sets a balance without a ledger row behind it.
 */
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
