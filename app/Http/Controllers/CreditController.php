<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\LedgerWriter;
use App\Models\User;
use App\Pixelforge\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CreditController extends Controller
{
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
