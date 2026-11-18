<?php

declare(strict_types=1);

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\TokenType;
use App\Pixelforge\Money;
use App\Pixelforge\PricingCatalog;
use App\Pixelforge\PricingEngine;
use App\Pixelforge\Rates;
use App\Pixelforge\TokenUsage;

function catalog(): PricingCatalog
{
    // The real GET /v1/pricing response, captured from the live API.
    return PricingCatalog::fromArray(
        json_decode(file_get_contents(__DIR__.'/../../Fixtures/pricing.json'), true, flags: JSON_THROW_ON_ERROR)
    );
}

function engine(): PricingEngine
{
    return new PricingEngine(markupPercent: 25);
}

describe('rate representability', function () {
    it('expresses every published rate as whole nanodollars per token', function () {
        $rates = catalog()->rates;

        expect($rates->nanodollarsPerToken(TokenType::InputText))->toBe(5_000)
            ->and($rates->nanodollarsPerToken(TokenType::InputImage))->toBe(10_000)
            ->and($rates->nanodollarsPerToken(TokenType::Output))->toBe(40_000)
            ->and($rates->nanodollarsPerToken(TokenType::Reasoning))->toBe(12_500);
    });

    it('keeps the 25% markup integral for every single-token cost', function () {
        $rates = catalog()->rates;
        $engine = engine();

        // If any rate x 5/4 were fractional, user charges would need rounding.
        foreach (TokenType::cases() as $type) {
            $oneToken = Money::fromNanodollars($rates->nanodollarsPerToken($type));

            expect(fn () => $engine->applyMarkup($oneToken))->not->toThrow(InvalidArgumentException::class);
        }
    });

    it('rejects a rate that cannot be billed exactly per token', function () {
        // $0.0000005 per 1M tokens would be half a nanodollar per token.
        Rates::fromArray([
            'input_text_tokens' => '0.0000005',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ]);
    })->throws(InvalidArgumentException::class, 'not a whole number of nanodollars');

    it('rejects float rates, which have already lost exactness', function () {
        Rates::fromArray([
            'input_text_tokens' => 5.00,
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ]);
    })->throws(InvalidArgumentException::class, 'arrived as a float');
});

describe('settlement', function () {
    it('matches the exact cost GET /v1/usage reported for a real generation', function () {
        // Captured live on 2026-11-18: gen_01M7QGGMFMM7TBT340E60XRD0Z,
        // low quality / 1024x1024, which /v1/usage billed at "0.011707500".
        $settled = engine()->settle(
            TokenUsage::of([
                'input_text_tokens' => 9,
                'input_image_tokens' => 0,
                'output_tokens' => 280,
                'reasoning_tokens' => 37,
            ]),
            catalog()->rates,
        );

        expect($settled->providerCost->toUsdString())->toBe('0.011707500')
            ->and($settled->providerCost->nanodollars)->toBe(11_707_500)
            ->and($settled->userCharge->nanodollars)->toBe(14_634_375)
            ->and($settled->userCharge->toUsdString())->toBe('0.014634375')
            ->and($settled->profit()->nanodollars)->toBe(2_926_875);
    });

    it('matches the worked example in the provider docs', function () {
        // Docs: 12 text, 765 image, 6450 output, 3184 reasoning.
        $settled = engine()->settle(
            TokenUsage::of([
                'input_text_tokens' => 12,
                'input_image_tokens' => 765,
                'output_tokens' => 6450,
                'reasoning_tokens' => 3184,
            ]),
            catalog()->rates,
        );

        expect($settled->providerCost->toUsdString())->toBe('0.305510000');
    });

    it('charges nothing for zero usage', function () {
        $settled = engine()->settle(TokenUsage::of([]), catalog()->rates);

        expect($settled->providerCost->isZero())->toBeTrue()
            ->and($settled->userCharge->isZero())->toBeTrue();
    });

    it('costs from the generation\'s own rate snapshot, not current pricing', function () {
        // A generation created when output cost $80/1M must still bill at $80,
        // even though the live catalogue now says $40.
        $snapshot = Rates::fromArray([
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '80.00',
            'reasoning_tokens' => '12.50',
        ]);

        $usage = TokenUsage::of(['output_tokens' => 1000]);

        expect(engine()->settle($usage, $snapshot)->providerCost->toUsdString())->toBe('0.080000000')
            ->and(engine()->settle($usage, catalog()->rates)->providerCost->toUsdString())->toBe('0.040000000');
    });

    it('rejects a usage payload whose total contradicts its parts', function () {
        TokenUsage::fromArray([
            'input_text_tokens' => 9,
            'input_image_tokens' => 0,
            'output_tokens' => 280,
            'reasoning_tokens' => 37,
            'total_tokens' => 999,
        ]);
    })->throws(InvalidArgumentException::class, 'does not equal the sum of its parts');
});

describe('estimates', function () {
    it('reproduces the maximum-cost worked example from the docs', function () {
        // Docs: "the most a generation can cost" -- high, 1536x1024,
        // 400-character prompt, 2 reference images => $0.373875.
        $estimate = engine()->estimate(
            catalog(),
            prompt: str_repeat('a', 400),
            quality: GenerationQuality::High,
            size: GenerationSize::Landscape,
            referenceImageCount: 2,
        );

        expect($estimate->providerMax->toUsdString())->toBe('0.373875000');
    });

    it('breaks the maximum down exactly as the docs table does', function () {
        $estimate = engine()->estimate(
            catalog(),
            prompt: str_repeat('a', 400),
            quality: GenerationQuality::High,
            size: GenerationSize::Landscape,
            referenceImageCount: 2,
        );

        $byType = collect($estimate->breakdown)->keyBy('type');

        expect($byType[TokenType::InputText->value]['tokens_max'])->toBe(115)
            ->and($byType[TokenType::InputImage->value]['tokens_max'])->toBe(1530)
            ->and($byType[TokenType::Output->value]['tokens_max'])->toBe(6450)
            ->and($byType[TokenType::Reasoning->value]['tokens_max'])->toBe(8000);
    });

    it('marks output and image tokens exact, text and reasoning not', function () {
        $byType = collect(engine()->estimate(
            catalog(), 'a prompt', GenerationQuality::Medium, GenerationSize::Square
        )->breakdown)->keyBy('type');

        expect($byType[TokenType::Output->value]['exact'])->toBeTrue()
            ->and($byType[TokenType::InputImage->value]['exact'])->toBeTrue()
            ->and($byType[TokenType::InputText->value]['exact'])->toBeFalse()
            ->and($byType[TokenType::Reasoning->value]['exact'])->toBeFalse();
    });

    it('orders the band min <= expected <= max', function () {
        foreach (GenerationQuality::cases() as $quality) {
            foreach (GenerationSize::cases() as $size) {
                $estimate = engine()->estimate(catalog(), 'a lighthouse at dawn', $quality, $size, 1);

                expect($estimate->providerMin->nanodollars)
                    ->toBeLessThanOrEqual($estimate->providerExpected->nanodollars)
                    ->and($estimate->providerExpected->nanodollars)
                    ->toBeLessThanOrEqual($estimate->providerMax->nanodollars);
            }
        }
    });

    it('applies the markup consistently across the whole band', function () {
        $estimate = engine()->estimate(catalog(), 'a prompt', GenerationQuality::High, GenerationSize::Portrait, 3);

        expect($estimate->chargeMin->nanodollars)->toBe(intdiv($estimate->providerMin->nanodollars * 5, 4))
            ->and($estimate->chargeExpected->nanodollars)->toBe(intdiv($estimate->providerExpected->nanodollars * 5, 4))
            ->and($estimate->chargeMax->nanodollars)->toBe(intdiv($estimate->providerMax->nanodollars * 5, 4));
    });

    it('uses the provider\'s own ceil(characters / 4) text estimator', function () {
        expect(catalog()->estimateTextTokens(str_repeat('a', 400)))->toBe(100)
            ->and(catalog()->estimateTextTokens(str_repeat('a', 401)))->toBe(101)
            ->and(catalog()->estimateTextTokens(''))->toBe(0);
    });

    it('counts unicode characters, not bytes', function () {
        // 4 multibyte characters is one token, not three.
        expect(catalog()->estimateTextTokens('日本語で'))->toBe(1);
    });

    it('brackets the settled cost for every reasoning outcome in range', function () {
        $prompt = 'a brass astrolabe resting on dark blue velvet';

        foreach (GenerationQuality::cases() as $quality) {
            $estimate = engine()->estimate(catalog(), $prompt, $quality, GenerationSize::Square, 1);
            $range = catalog()->reasoningRangeFor($quality);
            $text = catalog()->textTokenRange($prompt);

            // Sweep the corners of the real uncertainty: reasoning and text both
            // at their documented extremes.
            foreach ([$range['min'], $range['max']] as $reasoning) {
                foreach ([$text['min'], $text['max']] as $textTokens) {
                    $settled = engine()->settle(
                        TokenUsage::of([
                            'input_text_tokens' => $textTokens,
                            'input_image_tokens' => catalog()->imageTokensFor(1),
                            'output_tokens' => catalog()->outputTokensFor($quality, GenerationSize::Square),
                            'reasoning_tokens' => $reasoning,
                        ]),
                        catalog()->rates,
                    );

                    expect($estimate->brackets($settled->providerCost))->toBeTrue();
                }
            }
        }
    });
});
