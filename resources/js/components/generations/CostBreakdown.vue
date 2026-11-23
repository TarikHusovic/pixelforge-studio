<script setup lang="ts">
import { computed } from 'vue';
import { formatUsd, formatUsdExact } from '@/lib/money';
import { breakdown } from '@/lib/pricing';
import type { Quote } from '@/lib/pricing';
import type { Pricing } from '@/types';

const props = defineProps<{
    pricing: Pricing;
    prompt: string;
    size: string;
    quality: string;
    referenceImages: number;
}>();

const COLUMNS = ['estimated', 'max'] as const;

const receipt = computed(() =>
    breakdown(
        props.pricing,
        props.prompt,
        props.size,
        props.quality,
        props.referenceImages,
    ),
);

const lines = computed(() => {
    const { tokens } = props.pricing;
    const { usage, costs } = receipt.value;
    const reasoning = tokens.reasoning[props.quality];

    return [
        {
            label: 'Prompt text',
            detail: `≈ ${count(usage.estimated.input_text_tokens)} tokens ±${Math.round(tokens.text_token_variance * 100)}%`,
            amount: costs.input_text_tokens,
        },
        {
            label: 'Reference images',
            detail: props.referenceImages
                ? `${props.referenceImages} × ${count(tokens.per_reference_image)} tokens`
                : 'None',
            amount: costs.input_image_tokens,
        },
        {
            label: 'Image output',
            detail: `${count(usage.estimated.output_tokens)} tokens`,
            amount: costs.output_tokens,
        },
        {
            label: 'Reasoning',
            detail: `${count(reasoning.min)}–${count(reasoning.max)} tokens`,
            amount: costs.reasoning_tokens,
        },
    ];
});

const totals = computed((): { label: string; amount: Quote }[] => [
    { label: 'Pixelforge cost', amount: receipt.value.providerCost },
    {
        label: `+${props.pricing.markup_percent}% markup`,
        amount: receipt.value.fee,
    },
    { label: 'Your price', amount: receipt.value.total },
]);

function count(tokens: number): string {
    return tokens.toLocaleString('en-US');
}
</script>

<template>
    <div class="grid gap-2 rounded-lg border border-dashed bg-background p-3">
        <table class="w-full text-xs">
            <caption class="sr-only">
                How the price is worked out
            </caption>
            <thead class="text-muted-foreground">
                <tr>
                    <th scope="col" class="pb-1 text-left font-normal">
                        <span class="sr-only">Item</span>
                    </th>
                    <th scope="col" class="pb-1 pl-2 text-right font-normal">
                        Estimated
                    </th>
                    <th scope="col" class="pb-1 pl-2 text-right font-normal">
                        Up to
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="line in lines" :key="line.label" class="align-top">
                    <th scope="row" class="py-1 text-left font-normal">
                        {{ line.label }}
                        <span class="block text-muted-foreground">
                            {{ line.detail }}
                        </span>
                    </th>
                    <td
                        v-for="column in COLUMNS"
                        :key="column"
                        class="py-1 pl-2 text-right font-mono tabular-nums"
                        :title="formatUsdExact(line.amount[column])"
                    >
                        {{ formatUsd(line.amount[column]) }}
                    </td>
                </tr>
            </tbody>
            <tbody class="border-t border-dashed">
                <tr
                    v-for="total in totals"
                    :key="total.label"
                    class="last:border-t last:text-sm last:font-medium"
                >
                    <th
                        scope="row"
                        class="py-1 text-left [font-weight:inherit]"
                    >
                        {{ total.label }}
                    </th>
                    <td
                        v-for="column in COLUMNS"
                        :key="column"
                        class="py-1 pl-2 text-right font-mono tabular-nums"
                        :title="formatUsdExact(total.amount[column])"
                    >
                        {{ formatUsd(total.amount[column]) }}
                    </td>
                </tr>
            </tbody>
        </table>
        <p class="text-xs text-muted-foreground">
            Text and reasoning tokens are only counted once the image is done,
            and you pay for what it actually used. "Up to" is the most it can
            use. Failed generations are free.
        </p>
    </div>
</template>
