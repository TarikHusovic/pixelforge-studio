import type { Nanos, Pricing } from '@/types';

/**
 * Mirrors App\Billing\Pricing::estimate(), price(), providerCost() and withMarkup(),
 * so the browser quotes exactly what the server stores. Keep the two in sync
 * (tests/js/pricing.test.ts checks the same cases as tests/Unit/PricingTest.php).
 *
 * breakdown() has no PHP counterpart: it only explains a quote in the composer.
 * It prices the same usage as estimate(), so its totals are estimate()'s.
 *
 * BigInt because providerCost() sums usage x rate before dividing, which outgrows
 * Number's exact range long before PHP's int64 does.
 */

export type Usage = {
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
};

/** The expected amount, and the most it can be once the real token counts are in. */
export type Quote = { estimated: Nanos; max: Nanos };

export type Breakdown = {
    usage: { estimated: Usage; max: Usage };
    /** What Pixelforge charges for each token type. */
    costs: Record<keyof Usage, Quote>;
    providerCost: Quote;
    fee: Quote;
    total: Quote;
};

export function estimate(
    pricing: Pricing,
    prompt: string,
    size: string,
    quality: string,
    referenceImages: number,
): Quote {
    const usage = expectedUsage(
        pricing,
        prompt,
        size,
        quality,
        referenceImages,
    );

    return {
        estimated: price(pricing, usage.estimated),
        max: price(pricing, usage.max),
    };
}

export function breakdown(
    pricing: Pricing,
    prompt: string,
    size: string,
    quality: string,
    referenceImages: number,
): Breakdown {
    const usage = expectedUsage(
        pricing,
        prompt,
        size,
        quality,
        referenceImages,
    );
    const both = (amount: (usage: Usage) => Nanos): Quote => ({
        estimated: amount(usage.estimated),
        max: amount(usage.max),
    });
    const costOf = (tokenType: keyof Usage) =>
        both((tokens) =>
            providerCost({ [tokenType]: tokens[tokenType] }, pricing.rates),
        );

    const provider = both((tokens) => providerCost(tokens, pricing.rates));
    const total = both((tokens) => price(pricing, tokens));

    return {
        usage,
        costs: {
            input_text_tokens: costOf('input_text_tokens'),
            input_image_tokens: costOf('input_image_tokens'),
            output_tokens: costOf('output_tokens'),
            reasoning_tokens: costOf('reasoning_tokens'),
        },
        providerCost: provider,
        fee: {
            estimated: total.estimated - provider.estimated,
            max: total.max - provider.max,
        },
        total,
    };
}

/** Counted like the server: trimmed (Laravel trims input), in code points like mb_strlen. */
export function countCharacters(prompt: string): number {
    return Array.from(prompt.trim()).length;
}

/** The token counts Pricing::estimate() prices: the expected ones and the most there can be. */
function expectedUsage(
    pricing: Pricing,
    prompt: string,
    size: string,
    quality: string,
    referenceImages: number,
): { estimated: Usage; max: Usage } {
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
        estimated: {
            ...knownUpFront,
            input_text_tokens: textTokens,
            reasoning_tokens: Math.floor((reasoning.min + reasoning.max) / 2),
        },
        max: {
            ...knownUpFront,
            input_text_tokens: Math.ceil(
                (textTokens * (100 + variancePercent)) / 100,
            ),
            reasoning_tokens: reasoning.max,
        },
    };
}

export function price(pricing: Pricing, usage: Record<string, number>): Nanos {
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
