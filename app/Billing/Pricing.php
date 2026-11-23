<?php

declare(strict_types=1);

namespace App\Billing;

use App\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Cache;

/**
 * Pixelforge's published pricing plus our markup. Amounts are nanodollars (see Money).
 *
 * resources/js/lib/pricing.ts mirrors estimate() for the live estimate in the browser.
 *
 * @phpstan-type Tokens array{
 *     characters_per_text_token: int,
 *     text_token_variance: float,
 *     per_reference_image: int,
 *     output: array<string, array<string, int>>,
 *     reasoning: array<string, array{min: int, max: int}>,
 * }
 */
final readonly class Pricing
{
    /** For new generations; each one keeps the markup it was quoted at. */
    public const int MARKUP_PERCENT = 25;

    /**
     * @param  array<string, int>  $rates  token type => nanodollars per 1M tokens
     * @param  Tokens  $tokens
     */
    public function __construct(
        public array $rates,
        public array $tokens,
    ) {}

    /** Rates can change, so they come from Pixelforge, cached briefly and refreshed in the background. */
    public static function current(): self
    {
        /** @var array{rates: array<string, string>, tokens: Tokens} $pricing */
        $pricing = Cache::flexible('pixelforge.pricing', [300, 3600], fn () => app(PixelforgeClient::class)->pricing());

        return new self(self::parseRates($pricing['rates']), $pricing['tokens']);
    }

    /**
     * @param  array<string, string>  $rates  Pixelforge's decimal strings, USD per 1M tokens
     * @return array<string, int>
     */
    public static function parseRates(array $rates): array
    {
        return array_map(Money::toNanos(...), $rates);
    }

    /** @return list<string> */
    public function qualities(): array
    {
        return array_keys($this->tokens['output']);
    }

    /** @return list<string> Pixelforge offers every size at every quality. */
    public function sizes(): array
    {
        return array_keys($this->tokens['output'][$this->qualities()[0]]);
    }

    /**
     * What the user will pay: the expected price, and the most it can be once the
     * text and reasoning token counts (only known after completion) come in.
     *
     * @return array{estimated: int, max: int}
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceImages): array
    {
        $textTokens = (int) ceil(mb_strlen($prompt) / $this->tokens['characters_per_text_token']);
        $variancePercent = (int) round($this->tokens['text_token_variance'] * 100);
        $reasoning = $this->tokens['reasoning'][$quality];

        $knownUpFront = [
            'input_image_tokens' => $referenceImages * $this->tokens['per_reference_image'],
            'output_tokens' => $this->tokens['output'][$quality][$size],
        ];

        return [
            'estimated' => $this->price($knownUpFront + [
                'input_text_tokens' => $textTokens,
                'reasoning_tokens' => intdiv($reasoning['min'] + $reasoning['max'], 2),
            ]),
            'max' => $this->price($knownUpFront + [
                'input_text_tokens' => (int) ceil($textTokens * (100 + $variancePercent) / 100),
                'reasoning_tokens' => $reasoning['max'],
            ]),
        ];
    }

    /**
     * What Pixelforge charges for $usage at $rates (nanodollars per 1M tokens).
     *
     * @param  array<string, int>  $usage
     * @param  array<string, int>  $rates
     */
    public static function providerCost(array $usage, array $rates): int
    {
        $total = 0;

        foreach ($rates as $tokenType => $ratePerMillion) {
            $total += ($usage[$tokenType] ?? 0) * $ratePerMillion;
        }

        // Exact while rates have at most 3 decimals (all of Pixelforge's do); finer rates
        // would leave a sub-nanodollar remainder, rounded half up here.
        return intdiv($total + 500_000, 1_000_000);
    }

    /** Rounds up, so a sub-nanodollar remainder can never undercharge. */
    public static function withMarkup(int $nanos, int $markupPercent): int
    {
        return intdiv($nanos * (100 + $markupPercent) + 99, 100);
    }

    /**
     * @return array{markup_percent: int, rates: array<string, int>, tokens: Tokens}
     */
    public function toArray(): array
    {
        return [
            'markup_percent' => self::MARKUP_PERCENT,
            'rates' => $this->rates,
            'tokens' => $this->tokens,
        ];
    }

    /** @param  array<string, int>  $usage */
    private function price(array $usage): int
    {
        return self::withMarkup(self::providerCost($usage, $this->rates), self::MARKUP_PERCENT);
    }
}
