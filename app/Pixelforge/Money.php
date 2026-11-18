<?php

declare(strict_types=1);

namespace App\Pixelforge;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An exact USD amount held as an integer number of nanodollars.
 *
 * Pixelforge reports money as an exact decimal string with nine decimal places
 * ("0.011707500") and explicitly recommends integer nanodollars, so one
 * nanodollar is the natural atom here: every amount the provider can express is
 * representable without rounding, and no float ever touches the money path.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    /** Nanodollars in one US dollar. */
    public const int SCALE = 1_000_000_000;

    /** Decimal places the provider uses, which SCALE is chosen to match. */
    public const int DECIMALS = 9;

    private function __construct(public int $nanodollars) {}

    public static function fromNanodollars(int $nanodollars): self
    {
        return new self($nanodollars);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parse an exact decimal USD string such as "12.50" or "0.011707500".
     *
     * Parsed by integer string manipulation rather than a cast, because
     * (int) (0.011707500 * 1e9) is off by one on some platforms.
     */
    public static function fromUsdString(string $usd): self
    {
        $trimmed = trim($usd);

        if (! preg_match('/^(?<sign>[+-]?)(?<whole>\d+)(?:\.(?<fraction>\d*))?$/', $trimmed, $m)) {
            throw new InvalidArgumentException("Not an exact decimal USD string: [{$usd}].");
        }

        $fraction = $m['fraction'] ?? '';

        if (strlen($fraction) > self::DECIMALS) {
            // Refuse silently losing precision rather than rounding behind the caller's back.
            if (rtrim(substr($fraction, self::DECIMALS), '0') !== '') {
                throw new InvalidArgumentException(
                    "USD string [{$usd}] has more precision than one nanodollar."
                );
            }

            $fraction = substr($fraction, 0, self::DECIMALS);
        }

        $nanodollars = (int) $m['whole'] * self::SCALE
            + (int) str_pad($fraction, self::DECIMALS, '0');

        return new self($m['sign'] === '-' ? -$nanodollars : $nanodollars);
    }

    public function plus(self $other): self
    {
        return new self($this->nanodollars + $other->nanodollars);
    }

    public function minus(self $other): self
    {
        return new self($this->nanodollars - $other->nanodollars);
    }

    public function timesTokens(int $tokens): self
    {
        if ($tokens < 0) {
            throw new InvalidArgumentException('Token counts cannot be negative.');
        }

        return new self($this->nanodollars * $tokens);
    }

    /**
     * Apply a markup expressed as an exact rational (numerator/denominator).
     *
     * A rational keeps the result exact: the 25% markup is x5/4, and every
     * per-token rate Pixelforge publishes is divisible by 4, so no remainder
     * can appear. If a future rate ever broke that, this throws instead of
     * quietly rounding a user's charge.
     */
    public function withMarkup(int $numerator, int $denominator): self
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Markup denominator cannot be zero.');
        }

        $scaled = $this->nanodollars * $numerator;

        if ($scaled % $denominator !== 0) {
            throw new InvalidArgumentException(
                "Markup {$numerator}/{$denominator} is not exactly representable for "
                ."{$this->nanodollars}nd; it would require sub-nanodollar precision."
            );
        }

        return new self(intdiv($scaled, $denominator));
    }

    public function isZero(): bool
    {
        return $this->nanodollars === 0;
    }

    public function isNegative(): bool
    {
        return $this->nanodollars < 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->nanodollars < $other->nanodollars;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->nanodollars >= $other->nanodollars;
    }

    /** The provider's wire format: exact, unrounded, nine decimal places. */
    public function toUsdString(): string
    {
        $sign = $this->nanodollars < 0 ? '-' : '';
        $abs = abs($this->nanodollars);

        return sprintf(
            '%s%d.%s',
            $sign,
            intdiv($abs, self::SCALE),
            str_pad((string) ($abs % self::SCALE), self::DECIMALS, '0', STR_PAD_LEFT)
        );
    }

    /**
     * A display string rounded to the given decimals (half-up), for the UI only.
     * Never round before summing; round once, at the edge.
     */
    public function toDisplayString(int $decimals = 6): string
    {
        if ($decimals < 0 || $decimals > self::DECIMALS) {
            throw new InvalidArgumentException('Display decimals must be between 0 and 9.');
        }

        $divisor = 10 ** (self::DECIMALS - $decimals);
        $sign = $this->nanodollars < 0 ? '-' : '';
        $abs = abs($this->nanodollars);

        // Half-up rounding on integers, so no float rounding mode surprises.
        $units = intdiv($abs + intdiv($divisor, 2), $divisor);
        $scale = 10 ** $decimals;

        return $decimals === 0
            ? sprintf('%s%d', $sign, $units)
            : sprintf('%s%d.%s', $sign, intdiv($units, $scale),
                str_pad((string) ($units % $scale), $decimals, '0', STR_PAD_LEFT));
    }

    /**
     * @return array{nanodollars: int, usd: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'nanodollars' => $this->nanodollars,
            'usd' => $this->toUsdString(),
        ];
    }

    public function __toString(): string
    {
        return $this->toUsdString();
    }
}
