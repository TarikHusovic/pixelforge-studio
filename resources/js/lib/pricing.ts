import type { Nanos, Pricing } from '@/types';

/**
 * Mirrors App\Billing\Pricing::estimate(), price(), providerCost() and withMarkup(),
 * so the browser quotes exactly what the server stores. Keep the two in sync
 * (tests/js/pricing.test.ts checks the same cases as tests/Unit/PricingTest.php).
 *
 * BigInt because providerCost() sums usage x rate before dividing, which outgrows
 * Number's exact range long before PHP's int64 does.
 */
export function estimate(
    pricing: Pricing,
    prompt: string,
    size: string,
    quality: string,
    referenceImages: number,
): { estimated: Nanos; max: Nanos } {
    const { tokens } = pricing;

    const textTokens = Math.ceil(
        countCharacters(prompt) / tokens.characters_per_text_token,
    );
    const variancePercent = Math.round(tokens.text_token_variance * 100);
    const reasoning = tokens.reasoning[quality];

    const knownUpFront = {
        input_image_tokens: referenceImages * tokens.per_reference_image,
        output_tokens: tokens.output[quality][size],
    };

    return {
        estimated: price(pricing, {
            ...knownUpFront,
            input_text_tokens: textTokens,
            reasoning_tokens: Math.floor((reasoning.min + reasoning.max) / 2),
        }),
        max: price(pricing, {
            ...knownUpFront,
            input_text_tokens: Math.ceil(
                (textTokens * (100 + variancePercent)) / 100,
            ),
            reasoning_tokens: reasoning.max,
        }),
    };
}

/** Counted like the server: trimmed (Laravel trims input), in code points like mb_strlen. */
export function countCharacters(prompt: string): number {
    return Array.from(prompt.trim()).length;
}

function price(pricing: Pricing, usage: Record<string, number>): Nanos {
    return withMarkup(
        providerCost(usage, pricing.rates),
        pricing.markup_percent,
    );
}

export function providerCost(
    usage: Record<string, number>,
    rates: Record<string, Nanos>,
): Nanos {
    let total = 0n;

    for (const [tokenType, ratePerMillion] of Object.entries(rates)) {
        total += BigInt(usage[tokenType] ?? 0) * BigInt(ratePerMillion);
    }

    return Number((total + 500_000n) / 1_000_000n);
}

export function withMarkup(nanos: Nanos, markupPercent: number): Nanos {
    return Number((BigInt(nanos) * BigInt(100 + markupPercent) + 99n) / 100n);
}
