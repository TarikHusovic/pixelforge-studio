// The browser's price mirror must quote what App\Billing\Pricing stores: these are
// the cases tests/Unit/PricingTest.php asserts, against the same Pixelforge payload.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    estimate,
    providerCost,
    withMarkup,
} from '../../resources/js/lib/pricing.ts';
import type { Pricing } from '../../resources/js/types/generation.ts';

const rates = {
    input_text_tokens: 5_000_000_000,
    input_image_tokens: 10_000_000_000,
    output_tokens: 40_000_000_000,
    reasoning_tokens: 12_500_000_000,
};

const pricing: Pricing = {
    markup_percent: 25,
    rates,
    tokens: {
        characters_per_text_token: 4,
        text_token_variance: 0.15,
        per_reference_image: 765,
        output: {
            low: { '1024x1024': 280, '1536x1024': 420, '1024x1536': 420 },
            medium: { '1024x1024': 1100, '1536x1024': 1650, '1024x1536': 1650 },
            high: { '1024x1024': 4300, '1536x1024': 6450, '1024x1536': 6450 },
        },
        reasoning: {
            low: { min: 0, max: 500 },
            medium: { min: 200, max: 2000 },
            high: { min: 1000, max: 8000 },
        },
    },
};

const lowSquare = (prompt: string) =>
    estimate(pricing, prompt, '1024x1024', 'low', 0);

await test("quotes the docs' worked example: 400 chars, high 1536x1024, 2 refs", () => {
    assert.deepEqual(
        estimate(pricing, 'a'.repeat(400), '1536x1024', 'high', 2),
        { estimated: 412_562_500, max: 467_343_750 },
    );
});

await test('rounds the extra 15% of text tokens up for the max', () => {
    assert.deepEqual(
        estimate(pricing, 'A lighthouse', '1024x1024', 'medium', 0),
        { estimated: 72_206_250, max: 86_275_000 },
    );
});

await test('counts a partial text token as a whole one', () => {
    assert.deepEqual(lowSquare('a'.repeat(401)), lowSquare('a'.repeat(404)));
    assert.notDeepEqual(lowSquare('a'.repeat(401)), lowSquare('a'.repeat(400)));
});

await test('counts a multi-byte character as one character', () => {
    assert.deepEqual(lowSquare('ééééüüüü'), lowSquare('aaaaaaaa'));
    assert.deepEqual(lowSquare('🎨🎨🎨🎨'), lowSquare('aaaa'));
});

await test("costs the docs' succeeded generation at $0.305510000", () => {
    const usage = {
        input_text_tokens: 12,
        input_image_tokens: 765,
        output_tokens: 6450,
        reasoning_tokens: 3184,
        total_tokens: 10411,
    };

    assert.equal(providerCost(usage, rates), 305_510_000);
});

await test("costs the docs' usage report day at $0.169080000", () => {
    const usage = {
        input_text_tokens: 41,
        input_image_tokens: 0,
        output_tokens: 3300,
        reasoning_tokens: 2950,
    };

    assert.equal(providerCost(usage, rates), 169_080_000);
});

await test('costs one token at $1.00 per million as $0.000001', () => {
    assert.equal(
        providerCost({ output_tokens: 1 }, { output_tokens: 1_000_000_000 }),
        1_000,
    );
});

await test('adds 25% and rounds up to the nanodollar', () => {
    for (const [providerNanos, userNanos] of [
        [0, 0],
        [4, 5],
        [1, 2],
        [3, 4],
        [305_510_000, 381_887_500],
    ]) {
        assert.equal(withMarkup(providerNanos, 25), userNanos);
    }
});
