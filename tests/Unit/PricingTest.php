<?php

declare(strict_types=1);

use App\Billing\Money;
use App\Billing\Pricing;

function docsPricing(): Pricing
{
    $pricing = pixelforgePricing();

    return new Pricing(Pricing::parseRates($pricing['rates']), $pricing['tokens']);
}

/** @return array<string, int> */
function docsRates(): array
{
    return Pricing::parseRates(pixelforgePricing()['rates']);
}

it('quotes the docs\' worked maximum plus 25%', function () {
    // high 1536x1024, 400 characters, 2 reference images: Pixelforge's max is $0.373875000
    $quote = docsPricing()->estimate(str_repeat('a', 400), '1536x1024', 'high', 2);

    expect($quote['max'])->toBe(467_343_750);
});

it('estimates with the expected text tokens and the midpoint of the reasoning range', function () {
    // text 100 x $5 + image 1530 x $10 + output 6450 x $40 + reasoning 4500 x $12.50 = $0.330050000
    $quote = docsPricing()->estimate(str_repeat('a', 400), '1536x1024', 'high', 2);

    expect($quote['estimated'])->toBe(412_562_500);
});

it('counts a partial text token as a whole one', function () {
    $pricing = docsPricing();

    expect($pricing->estimate(str_repeat('a', 401), '1024x1024', 'low', 0))
        ->toBe($pricing->estimate(str_repeat('a', 404), '1024x1024', 'low', 0))
        ->not->toBe($pricing->estimate(str_repeat('a', 400), '1024x1024', 'low', 0));
});

it('rounds the extra 15% of text tokens up for the max', function () {
    // 12 characters = 3 text tokens, +15% = 3.45 => 4: 4 x $5 + 1100 x $40 + 2000 x $12.50 = $0.069020000
    $quote = docsPricing()->estimate('A lighthouse', '1024x1024', 'medium', 0);

    expect($quote['max'])->toBe(86_275_000);
});

it('counts a multi-byte character as one character', function () {
    $pricing = docsPricing();

    expect($pricing->estimate('ééééüüüü', '1024x1024', 'low', 0))
        ->toBe($pricing->estimate('aaaaaaaa', '1024x1024', 'low', 0))
        ->and($pricing->estimate('🎨🎨🎨🎨', '1024x1024', 'low', 0))
        ->toBe($pricing->estimate('aaaa', '1024x1024', 'low', 0));
});

it('costs the docs\' succeeded generation at $0.305510000', function () {
    $usage = ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411];

    expect(Pricing::cost($usage, docsRates()))->toBe(305_510_000);
});

it('costs the docs\' usage report day at $0.169080000', function () {
    $usage = ['input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950];

    expect(Pricing::cost($usage, docsRates()))->toBe(169_080_000);
});

it('costs one token at $1.00 per million as $0.000001', function () {
    expect(Pricing::cost(['output_tokens' => 1], ['output_tokens' => Money::fromDecimal('1.00')]))->toBe(1_000);
});

it('adds 25% and rounds up to the nanodollar', function (int $providerCost, int $userPrice) {
    expect(Pricing::withMarkup($providerCost))->toBe($userPrice);
})->with([
    'nothing' => [0, 0],
    'exact' => [4, 5],
    '1.25 rounds up' => [1, 2],
    '3.75 rounds up' => [3, 4],
    'the docs\' generation' => [305_510_000, 381_887_500],
]);

it('offers the qualities and sizes Pixelforge prices', function () {
    expect(docsPricing())
        ->qualities()->toBe(['low', 'medium', 'high'])
        ->sizes()->toBe(['1024x1024', '1536x1024', '1024x1536']);
});

it('reads exact decimal dollars as nanodollars', function (string $usd, int $nanos) {
    expect(Money::fromDecimal($usd))->toBe($nanos);
})->with([
    ['0.046125000', 46_125_000],
    ['0.373875000', 373_875_000],
    ['12.50', 12_500_000_000],
    ['5', 5_000_000_000],
    ['0.000000001', 1],
    ['0.000000000', 0],
]);

it('refuses anything that isn\'t an exact non-negative dollar amount', function (string $usd) {
    Money::fromDecimal($usd);
})->throws(InvalidArgumentException::class)->with([
    'negative' => '-1.00',
    'beyond nanodollars' => '0.0000000001',
    'exponent' => '1e3',
    'empty' => '',
    'comma' => '1,50',
    'dangling point' => '1.',
    'leading point' => '.5',
    'padded' => ' 1.00',
]);
