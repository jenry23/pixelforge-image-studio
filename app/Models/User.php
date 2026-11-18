<?php

namespace App\Models;

use App\Pixelforge\Money;
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
    use HasFactory, Notifiable;

    protected $attributes = [
        'is_admin' => false,
        'balance_nanodollars' => 0,
        'held_nanodollars' => 0,
    ];

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

    public function balance(): Money
    {
        return Money::fromNanodollars((int) ($this->balance_nanodollars ?? 0));
    }

    public function held(): Money
    {
        return Money::fromNanodollars((int) ($this->held_nanodollars ?? 0));
    }

    public function available(): Money
    {
        return $this->balance()->minus($this->held());
    }

    public function canAfford(Money $amount): bool
    {
        return $this->available()->greaterThanOrEqual($amount);
    }

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
