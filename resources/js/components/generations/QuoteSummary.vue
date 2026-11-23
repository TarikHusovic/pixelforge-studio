<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { formatUsd, formatUsdExact } from '@/lib/money';
import type { Quote } from '@/lib/pricing';

defineProps<{
    quote: Quote;
}>();
</script>

<template>
    <Collapsible v-slot="{ open }" class="grid gap-1">
        <span class="text-xs text-muted-foreground">Estimated</span>
        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
            <span
                class="font-mono text-3xl tabular-nums"
                :title="formatUsdExact(quote.estimated)"
            >
                {{ formatUsd(quote.estimated) }}
            </span>
            <CollapsibleTrigger as-child>
                <Button
                    type="button"
                    variant="ghost"
                    class="-mr-2 px-2 font-normal text-muted-foreground"
                >
                    How is this priced?
                    <ChevronDown
                        class="transition-transform"
                        :class="{ 'rotate-180': open }"
                    />
                </Button>
            </CollapsibleTrigger>
        </div>
        <p class="text-sm text-muted-foreground">
            Up to
            <span
                class="font-mono text-foreground tabular-nums"
                :title="formatUsdExact(quote.max)"
                >{{ formatUsd(quote.max) }}</span
            >
            is held until it's done.
        </p>
        <CollapsibleContent class="pt-2">
            <slot />
        </CollapsibleContent>
    </Collapsible>
</template>
