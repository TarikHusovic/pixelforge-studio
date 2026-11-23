<script setup lang="ts">
import { Deferred } from '@inertiajs/vue3';
import {
    CircleAlert,
    CircleCheck,
    LoaderCircle,
    TriangleAlert,
} from '@lucide/vue';
import { computed } from 'vue';
import type {
    Amounts,
    PixelforgeReport,
    ReportDay,
} from '@/components/admin/report';
import { generationCount, signedUsdExact } from '@/components/admin/report';
import { Skeleton } from '@/components/ui/skeleton';
import { formatUsd, formatUsdExact } from '@/lib/money';

/** What Pixelforge's GET /v1/usage says for the range, and whether our provider cost matches it. */
const props = defineProps<{
    days: ReportDay[];
    totals: Amounts;
    /** Deferred: missing while it loads, and when Pixelforge couldn't be reached. */
    pixelforge?: PixelforgeReport;
}>();

const WARNING =
    'border-destructive/30 bg-destructive/10 text-destructive-foreground';

const verdict = computed(() => {
    // Pixelforge lists every day, so a missing one counts as zero.
    const differing = props.days.filter(
        (day) =>
            day.provider_cost_nanos !==
            (props.pixelforge?.days[day.date]?.cost_nanos ?? 0),
    ).length;
    const net =
        props.totals.provider_cost_nanos -
        (props.pixelforge?.total.cost_nanos ?? 0);
    const dayCount = `${props.days.length} ${props.days.length === 1 ? 'day' : 'days'}`;

    if (differing === 0 && net === 0) {
        return {
            matches: true,
            title: 'Matches Pixelforge to the nanodollar',
            detail: 'Our provider cost equals GET /v1/usage on every day of this range.',
        };
    }

    return {
        matches: false,
        title: "Doesn't match Pixelforge",
        detail: `Differs on ${differing} of ${dayCount}, ${signedUsdExact(net)} net (ours minus Pixelforge's). Generations that completed in the last few minutes can lag until their webhook or the minute-ly poll lands.`,
    };
});
</script>

<template>
    <div
        class="flex flex-col gap-4 rounded-xl border bg-card p-4 md:flex-row md:items-center md:justify-between"
        aria-live="polite"
    >
        <dl class="grid shrink-0 gap-1">
            <dt class="text-sm text-muted-foreground">Pixelforge reports</dt>
            <Deferred data="pixelforge">
                <template #fallback>
                    <dd><Skeleton class="h-8 w-32" /></dd>
                    <dd><Skeleton class="h-4 w-44" /></dd>
                </template>
                <template #rescue>
                    <dd class="font-mono text-2xl text-muted-foreground">—</dd>
                    <dd class="text-xs text-muted-foreground">
                        GET /v1/usage didn't answer
                    </dd>
                </template>
                <template v-if="pixelforge">
                    <dd
                        class="font-mono text-2xl tabular-nums"
                        :title="formatUsdExact(pixelforge.total.cost_nanos)"
                    >
                        {{ formatUsd(pixelforge.total.cost_nanos) }}
                    </dd>
                    <dd class="text-xs text-muted-foreground">
                        GET /v1/usage,
                        {{ generationCount(pixelforge.total.generations) }}
                    </dd>
                </template>
            </Deferred>
        </dl>

        <Deferred data="pixelforge">
            <template #fallback>
                <p
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <LoaderCircle class="size-4 shrink-0 animate-spin" />
                    Checking our provider cost against Pixelforge's GET
                    /v1/usage…
                </p>
            </template>
            <template #rescue>
                <div
                    class="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm md:max-w-xl"
                    :class="WARNING"
                >
                    <CircleAlert class="mt-0.5 size-4 shrink-0" />
                    <div class="grid gap-0.5">
                        <p class="font-medium">Couldn't reach Pixelforge</p>
                        <p>
                            This range isn't checked against GET /v1/usage.
                            Reload the page to try again.
                        </p>
                    </div>
                </div>
            </template>
            <div
                class="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm md:max-w-xl"
                :class="
                    verdict.matches
                        ? 'border-success/30 bg-success-soft text-success'
                        : WARNING
                "
            >
                <CircleCheck
                    v-if="verdict.matches"
                    class="mt-0.5 size-4 shrink-0"
                />
                <TriangleAlert v-else class="mt-0.5 size-4 shrink-0" />
                <div class="grid gap-0.5">
                    <p class="font-medium">{{ verdict.title }}</p>
                    <p>{{ verdict.detail }}</p>
                </div>
            </div>
        </Deferred>
    </div>
</template>
