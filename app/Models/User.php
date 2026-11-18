<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Pixelforge\Money;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * In-memory defaults matching the column defaults.
     *
     * Without these, a freshly created model has null balances until it is
     * reloaded, because database defaults are not hydrated back into the
     * instance that inserted the row.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_admin' => false,
        'balance_nanodollars' => 0,
        'held_nanodollars' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    // -- Credits -------------------------------------------------------------

    /** Credits owned, including any currently reserved by a running generation. */
    public function balance(): Money
    {
        return Money::fromNanodollars((int) ($this->balance_nanodollars ?? 0));
    }

    /** Credits reserved against generations that have not settled yet. */
    public function held(): Money
    {
        return Money::fromNanodollars((int) ($this->held_nanodollars ?? 0));
    }

    /**
     * What the user can actually spend right now.
     *
     * Holds are subtracted because a queued generation's cost is not yet known;
     * spending the same credits twice while both are in flight is exactly the
     * overdraft the hold mechanism exists to prevent.
     */
    public function available(): Money
    {
        return $this->balance()->minus($this->held());
    }

    public function canAfford(Money $amount): bool
    {
        return $this->available()->greaterThanOrEqual($amount);
    }

    // -- Ledger verification -------------------------------------------------

    /**
     * Balance folded from the append-only ledger, which is the source of truth.
     *
     * The `balance_nanodollars` column is a cache written in the same
     * transaction as each ledger row; `pixelforge:verify-ledger` compares the two
     * so any drift is caught rather than assumed impossible.
     *
     * @return array{balance: Money, held: Money}
     */
    public function foldLedger(): array
    {
        $sums = $this->creditTransactions()
            ->selectRaw('COALESCE(SUM(balance_delta_nanodollars), 0) as balance_sum')
            ->selectRaw('COALESCE(SUM(hold_delta_nanodollars), 0) as hold_sum')
            ->first();

        return [
            'balance' => Money::fromNanodollars((int) $sums->balance_sum),
            'held' => Money::fromNanodollars((int) $sums->hold_sum),
        ];
    }
}
