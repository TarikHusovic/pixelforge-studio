<script setup lang="ts">
import { Deferred } from '@inertiajs/vue3';
import { AlertTriangle, Check } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { formatUsdExact } from '@/lib/money';
import type { Nanos } from '@/types';

/** The "Pixelforge cost" and "Difference" cells of a report row, filled in by the deferred `pixelforge` prop. */
const props = defineProps<{
    /** Our provider cost. */
    ours: Nanos;
    /** Pixelforge's GET /v1/usage cost. Pixelforge lists every day, so a missing one counts as zero. */
    theirs?: Nanos;
}>();

const difference = computed(() => props.ours - (props.theirs ?? 0));
</script>

<template>
    <Deferred data="pixelforge">
        <template #fallback>
            <td colspan="2" class="px-3 py-2">
                <Skeleton class="ml-auto h-4 w-48" />
            </td>
        </template>

        <template #rescue>
            <td colspan="2" class="px-3 py-2 text-right text-muted-foreground">
                Unavailable
            </td>
        </template>

        <td class="px-3 py-2 text-right font-mono">
            {{ formatUsdExact(theirs ?? 0) }}
        </td>
        <td class="px-3 py-2">
            <Badge v-if="difference === 0" variant="secondary">
                <Check /> Matches
            </Badge>
            <Badge v-else variant="destructive" class="font-mono">
                <AlertTriangle />
                {{ difference > 0 ? '+' : '' }}{{ formatUsdExact(difference) }}
            </Badge>
        </td>
    </Deferred>
</template>
