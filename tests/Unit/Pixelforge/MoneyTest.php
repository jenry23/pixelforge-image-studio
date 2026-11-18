<?php

declare(strict_types=1);

use App\Pixelforge\Money;

it('parses the provider\'s nine-decimal money format exactly', function () {
    // The exact string GET /v1/usage returned for a real generation.
    expect(Money::fromUsdString('0.011707500')->nanodollars)->toBe(11_707_500);
    expect(Money::fromUsdString('0.046125000')->nanodollars)->toBe(46_125_000);
    expect(Money::fromUsdString('0.373875000')->nanodollars)->toBe(373_875_000);
    expect(Money::fromUsdString('12.50')->nanodollars)->toBe(12_500_000_000);
    expect(Money::fromUsdString('5')->nanodollars)->toBe(5_000_000_000);
    expect(Money::fromUsdString('0')->nanodollars)->toBe(0);
});

it('round-trips to the provider\'s wire format', function (string $usd) {
    expect(Money::fromUsdString($usd)->toUsdString())->toBe($usd);
})->with([
    '0.011707500',
    '0.373875000',
    '1.000000000',
    '0.000000001',
    '123.456789012',
]);

it('does not lose precision through a float cast', function () {
    // (int) (0.011707500 * 1e9) is 11707499 on some platforms; string parsing is not.
    expect(Money::fromUsdString('0.011707500')->nanodollars)
        ->not->toBe((int) (0.0117075 * Money::SCALE) - 1);

    expect(Money::fromUsdString('0.000000001')->nanodollars)->toBe(1);
});

it('refuses amounts finer than one nanodollar rather than rounding silently', function () {
    Money::fromUsdString('0.0000000005');
})->throws(InvalidArgumentException::class, 'more precision than one nanodollar');

it('accepts trailing zeros beyond nine decimals', function () {
    expect(Money::fromUsdString('0.01170750000')->nanodollars)->toBe(11_707_500);
});

it('rejects values that are not exact decimal strings', function (string $bad) {
    Money::fromUsdString($bad);
})->with(['', 'abc', '1.2.3', '1e-9', '$1.00', '1,000'])
    ->throws(InvalidArgumentException::class);

it('adds and subtracts without drift', function () {
    // The classic float failure: 0.1 + 0.2 !== 0.3
    $sum = Money::fromUsdString('0.100000000')->plus(Money::fromUsdString('0.200000000'));

    expect($sum->toUsdString())->toBe('0.300000000')
        ->and($sum->nanodollars)->toBe(300_000_000);
});

it('sums a thousand sub-cent amounts with no accumulated error', function () {
    $total = Money::zero();

    for ($i = 0; $i < 1000; $i++) {
        $total = $total->plus(Money::fromUsdString('0.011707500'));
    }

    // 1000 x 0.0117075 = 11.7075 exactly.
    expect($total->toUsdString())->toBe('11.707500000');
});

it('applies the 25% markup exactly', function () {
    $providerCost = Money::fromNanodollars(11_707_500);

    expect($providerCost->withMarkup(5, 4)->nanodollars)->toBe(14_634_375);
});

it('refuses a markup that would need sub-nanodollar precision', function () {
    // 1nd x 1/3 is not representable; better to throw than to round a charge.
    Money::fromNanodollars(1)->withMarkup(1, 3);
})->throws(InvalidArgumentException::class, 'not exactly representable');

it('formats for display without rounding the stored value', function () {
    $money = Money::fromNanodollars(11_707_500);

    expect($money->toDisplayString(6))->toBe('0.011708')   // half-up
        ->and($money->toDisplayString(2))->toBe('0.01')
        ->and($money->toUsdString())->toBe('0.011707500'); // storage untouched
});

it('rounds display half-up on exact halves', function () {
    expect(Money::fromNanodollars(5)->toDisplayString(8))->toBe('0.00000001');
});

it('reports negative amounts correctly', function () {
    $negative = Money::zero()->minus(Money::fromNanodollars(11_707_500));

    expect($negative->isNegative())->toBeTrue()
        ->and($negative->toUsdString())->toBe('-0.011707500');
});
