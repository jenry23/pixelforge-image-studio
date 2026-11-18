<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TransactionType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Money;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

final class LedgerWriter
{
    public function forUser(int $userId, callable $callback): mixed
    {
        return DB::transaction(function () use ($userId, $callback) {
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

            return $callback($user);
        });
    }

    public function hold(User $user, Generation $generation, Money $amount): ?CreditTransaction
    {
        $this->assertLocked();

        if ($amount->isNegative()) {
            throw new LogicException('Cannot hold a negative amount.');
        }

        if (! $user->canAfford($amount)) {
            return null;
        }

        $transaction = $this->write($user, $generation, TransactionType::Hold,
            balanceDelta: Money::zero(),
            holdDelta: $amount,
            description: 'Reserved against the maximum estimated cost',
        );

        $generation->forceFill(['hold_nanodollars' => $amount->nanodollars])->save();

        return $transaction;
    }

    public function release(User $user, Generation $generation, string $reason): ?CreditTransaction
    {
        $this->assertLocked();

        if ($generation->hold_nanodollars === 0) {
            return null;
        }

        if ($this->has($generation, TransactionType::Release) || $this->has($generation, TransactionType::Charge)) {
            return null;
        }

        $transaction = $this->write($user, $generation, TransactionType::Release,
            balanceDelta: Money::zero(),
            holdDelta: Money::fromNanodollars(-$generation->hold_nanodollars),
            description: $reason,
        );

        $generation->forceFill(['hold_nanodollars' => 0])->save();

        return $transaction;
    }

    public function charge(User $user, Generation $generation, Money $amount): ?CreditTransaction
    {
        $this->assertLocked();

        if ($amount->isNegative()) {
            throw new LogicException('Cannot charge a negative amount.');
        }

        if ($this->has($generation, TransactionType::Charge)) {
            return null;
        }

        $transaction = $this->write($user, $generation, TransactionType::Charge,
            balanceDelta: Money::fromNanodollars(-$amount->nanodollars),

            holdDelta: Money::fromNanodollars(-$generation->hold_nanodollars),
            description: 'Generation charged at settled cost',
        );

        $generation->forceFill(['hold_nanodollars' => 0])->save();

        return $transaction;
    }

    public function topup(User $user, Money $amount, string $description = 'Credits purchased'): CreditTransaction
    {
        $this->assertLocked();

        if ($amount->isNegative() || $amount->isZero()) {
            throw new LogicException('A top-up must be a positive amount.');
        }

        return $this->write($user, null, TransactionType::Topup,
            balanceDelta: $amount,
            holdDelta: Money::zero(),
            description: $description,
        );
    }

    private function write(
        User $user,
        ?Generation $generation,
        TransactionType $type,
        Money $balanceDelta,
        Money $holdDelta,
        string $description,
    ): CreditTransaction {
        $transaction = CreditTransaction::query()->create([
            'user_id' => $user->getKey(),
            'generation_id' => $generation?->getKey(),
            'type' => $type,
            'balance_delta_nanodollars' => $balanceDelta->nanodollars,
            'hold_delta_nanodollars' => $holdDelta->nanodollars,
            'description' => $description,
        ]);

        User::query()->whereKey($user->getKey())->update([
            'balance_nanodollars' => DB::raw('balance_nanodollars + '.$balanceDelta->nanodollars),
            'held_nanodollars' => DB::raw('held_nanodollars + '.$holdDelta->nanodollars),
        ]);

        $user->balance_nanodollars += $balanceDelta->nanodollars;
        $user->held_nanodollars += $holdDelta->nanodollars;

        return $transaction;
    }

    private function has(Generation $generation, TransactionType $type): bool
    {
        return CreditTransaction::query()
            ->where('generation_id', $generation->getKey())
            ->where('type', $type)
            ->exists();
    }

    private function assertLocked(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                'Ledger writes must happen inside LedgerWriter::forUser(), which holds the user row lock.'
            );
        }
    }
}
