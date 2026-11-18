<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\LedgerWriter;
use App\Models\User;
use App\Pixelforge\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Buy credits" -- a fake purchase, but a real ledger entry.
 *
 * No payment provider is involved, so the amounts are restricted to a fixed set
 * of packs rather than accepting an arbitrary number from the client. That keeps
 * a request body from being able to mint any balance it likes, which is the
 * habit the real version of this would need anyway.
 */
class CreditController extends Controller
{
    /** Dollar amounts a user may top up by. */
    public const array PACKS = ['1.00', '5.00', '20.00'];

    public function __construct(private readonly LedgerWriter $ledger) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'amount_usd' => ['required', 'string', Rule::in(self::PACKS)],
        ]);

        $amount = Money::fromUsdString($validated['amount_usd']);

        $this->ledger->forUser(
            $request->user()->getKey(),
            fn (User $user) => $this->ledger->topup(
                $user,
                $amount,
                "Credit pack purchased (\${$validated['amount_usd']})"
            )
        );

        return back()->with('success', "Added \${$validated['amount_usd']} of credits.");
    }
}
