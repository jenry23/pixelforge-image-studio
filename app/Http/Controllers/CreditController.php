<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\LedgerWriter;
use App\Http\Requests\StoreCreditRequest;
use App\Models\User;
use App\Support\CreditPacks;
use Illuminate\Http\RedirectResponse;

class CreditController extends Controller
{
    public function __construct(private readonly LedgerWriter $ledger) {}

    public function store(StoreCreditRequest $request): RedirectResponse
    {
        $amountUsd = $request->amountUsd();

        $this->ledger->forUser(
            $request->user()->getKey(),
            fn (User $user) => $this->ledger->topup(
                $user,
                CreditPacks::money($amountUsd),
                "Credit pack purchased (\${$amountUsd})"
            )
        );

        return back()->with('success', "Added \${$amountUsd} of credits.");
    }
}
