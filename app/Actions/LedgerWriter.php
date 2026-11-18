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

/**
 * The only writer of the credit ledger.
 *
 * Two rules make the money safe, and every method here depends on both:
 *
 *  1. Every mutation holds a `FOR UPDATE` lock on the user row first. That
 *     serialises all balance arithmetic for a user, so "check then insert" is
 *     atomic and two concurrent settlements cannot both see the same balance.
 *  2. `UNIQUE (generation_id, type)` means one hold, one release and one charge
 *     per generation at the database level. With rule 1 in place this should
 *     never fire; if it ever does, that is a logic bug and throwing is the
 *     correct outcome -- far better than silently double-charging.
 *
 * Ledger rows are append-only. The `users.balance_nanodollars` and
 * `held_nanodollars` columns are a cache updated in the same transaction, and
 * `pixelforge:verify-ledger` proves they still agree with the fold.
 */
final class LedgerWriter
{
    /**
     * Lock a user for a sequence of ledger writes and hand it to the callback.
     *
     * Always acquire this before touching a generation row, so every money path
     * takes locks in the same order and cannot deadlock against another.
     *
     * @template TReturn
     *
     * @param  callable(User): TReturn  $callback
     * @return TReturn
     */
    public function forUser(int $userId, callable $callback): mixed
    {
        return DB::transaction(function () use ($userId, $callback) {
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

            return $callback($user);
        });
    }

    /**
     * Reserve credits against a generation whose final cost is not yet known.
     *
     * Returns null when the user cannot afford the reservation, so the caller
     * can refuse the submission before anything is sent to the provider.
     */
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

    /**
     * Release a reservation without charging: the generation failed, was
     * rejected, or was abandoned. Idempotent -- returns null if already released.
     */
    public function release(User $user, Generation $generation, string $reason): ?CreditTransaction
    {
        $this->assertLocked();

        if ($generation->hold_nanodollars === 0) {
            return null;
        }

        if ($this->has($generation, TransactionType::Release) || $this->has($generation, TransactionType::Charge)) {
            // Already settled one way or the other.
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

    /**
     * Convert a reservation into an actual charge at the settled cost.
     *
     * The hold is released and the balance debited in the same row, so a user's
     * available credit never flickers between the two. Idempotent -- returns null
     * if this generation has already been charged.
     */
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
            // Settling consumes whatever was reserved, which may differ from the
            // final charge -- the hold was the maximum, this is the truth.
            holdDelta: Money::fromNanodollars(-$generation->hold_nanodollars),
            description: 'Generation charged at settled cost',
        );

        $generation->forceFill(['hold_nanodollars' => 0])->save();

        return $transaction;
    }

    /** Add credits. A fake purchase in this app, but a real ledger entry. */
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

    // -- internals -----------------------------------------------------------

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

        // Update the materialised cache in the same transaction as the ledger
        // row, as a relative change rather than a computed total, so it stays
        // correct under the row lock without re-reading.
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

    /**
     * Guard against being called outside the user lock, which would silently
     * remove the serialisation the whole design depends on.
     */
    private function assertLocked(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                'Ledger writes must happen inside LedgerWriter::forUser(), which holds the user row lock.'
            );
        }
    }
}
