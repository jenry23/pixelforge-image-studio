<?php

declare(strict_types=1);

namespace App\Pixelforge;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

final readonly class Money implements JsonSerializable, Stringable
{
    public const int SCALE = 1_000_000_000;

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

    public static function fromUsdString(string $usd): self
    {
        $trimmed = trim($usd);

        if (! preg_match('/^(?<sign>[+-]?)(?<whole>\d+)(?:\.(?<fraction>\d*))?$/', $trimmed, $m)) {
            throw new InvalidArgumentException("Not an exact decimal USD string: [{$usd}].");
        }

        $fraction = $m['fraction'] ?? '';

        if (strlen($fraction) > self::DECIMALS) {
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

    public function toDisplayString(int $decimals = 6): string
    {
        if ($decimals < 0 || $decimals > self::DECIMALS) {
            throw new InvalidArgumentException('Display decimals must be between 0 and 9.');
        }

        $divisor = 10 ** (self::DECIMALS - $decimals);
        $sign = $this->nanodollars < 0 ? '-' : '';
        $abs = abs($this->nanodollars);

        $units = intdiv($abs + intdiv($divisor, 2), $divisor);
        $scale = 10 ** $decimals;

        return $decimals === 0
            ? sprintf('%s%d', $sign, $units)
            : sprintf('%s%d.%s', $sign, intdiv($units, $scale),
                str_pad((string) ($units % $scale), $decimals, '0', STR_PAD_LEFT));
    }

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
