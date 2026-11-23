<script setup lang="ts">
import { formatUsd, formatUsdExact } from '@/lib/money';
import type { Nanos } from '@/types';

type OptionCard = {
    value: string;
    label: string;
    detail: string;
    /** The estimate if this option were picked. */
    price: Nanos;
    /** CSS aspect-ratio for a small shape glyph, e.g. "1536 / 1024". */
    aspectRatio?: string;
};

defineProps<{
    legend: string;
    name: string;
    options: OptionCard[];
}>();

const model = defineModel<string>({ required: true });
</script>

<template>
    <fieldset>
        <legend class="mb-2 text-sm font-medium">{{ legend }}</legend>
        <div class="grid grid-cols-3 gap-2">
            <label
                v-for="option in options"
                :key="option.value"
                class="grid cursor-pointer content-start gap-0.5 rounded-lg border bg-background p-2.5 text-muted-foreground transition-colors hover:border-foreground/30 has-checked:border-foreground/70 has-checked:bg-accent has-checked:text-foreground has-checked:inset-ring has-checked:inset-ring-foreground/70 has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
            >
                <input
                    v-model="model"
                    type="radio"
                    class="sr-only"
                    :name="name"
                    :value="option.value"
                />
                <span
                    v-if="option.aspectRatio"
                    aria-hidden="true"
                    class="mb-1.5 h-5 justify-self-start rounded-[3px] border-[1.5px] border-current"
                    :style="{ aspectRatio: option.aspectRatio }"
                />
                <span class="text-sm font-medium text-foreground">
                    {{ option.label }}
                </span>
                <span class="text-xs">{{ option.detail }}</span>
                <span
                    class="mt-1 font-mono text-xs tabular-nums"
                    :title="formatUsdExact(option.price)"
                >
                    {{ formatUsd(option.price) }}
                </span>
            </label>
        </div>
    </fieldset>
</template>
