<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import DailyChart from '@/components/admin/DailyChart.vue';
import DaysTable from '@/components/admin/DaysTable.vue';
import PixelforgeTile from '@/components/admin/PixelforgeTile.vue';
import type {
    Amounts,
    PixelforgeReport,
    ReportDay,
    ReportUser,
} from '@/components/admin/report';
import { generationCount } from '@/components/admin/report';
import ReportRange from '@/components/admin/ReportRange.vue';
import UsersTable from '@/components/admin/UsersTable.vue';
import Heading from '@/components/Heading.vue';
import { formatUsd, formatUsdExact } from '@/lib/money';
import { report } from '@/routes/admin';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin report', href: report() }],
    },
});

const props = defineProps<{
    /** Inclusive UTC days, after defaults and the 92-day limit. */
    range: { from: string; to: string };
    /** Newest first, every day of the range, zeros included. */
    days: ReportDay[];
    totals: Amounts;
    /** Biggest spenders first. */
    users: ReportUser[];
    /** Pixelforge's GET /v1/usage for the same days. Deferred; missing if Pixelforge failed. */
    pixelforge?: PixelforgeReport;
}>();

const tiles = computed(() => {
    const { generations, spend_nanos, provider_cost_nanos, profit_nanos } =
        props.totals;

    return [
        {
            label: 'Credit spend',
            value: formatUsd(spend_nanos),
            exact: formatUsdExact(spend_nanos),
            hint: `What users were charged for ${generationCount(generations)}`,
        },
        {
            label: 'Provider cost',
            value: formatUsd(provider_cost_nanos),
            exact: formatUsdExact(provider_cost_nanos),
            hint: 'What Pixelforge charged us, by our records',
        },
        {
            label: 'Profit',
            value: formatUsd(profit_nanos),
            exact: formatUsdExact(profit_nanos),
            hint: 'Credit spend minus provider cost',
        },
        {
            label: 'Margin',
            value:
                spend_nanos === 0
                    ? '—'
                    : `${((profit_nanos / spend_nanos) * 100).toFixed(1)}%`,
            exact: undefined,
            hint: 'Profit as a share of credit spend',
        },
    ];
});
</script>

<template>
    <Head title="Admin report" />

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-8 p-4">
        <header class="grid gap-1">
            <h1 class="font-display text-2xl">Admin report</h1>
            <p class="text-sm text-muted-foreground">
                Credit spend, provider cost and profit per UTC day and per user,
                checked against Pixelforge's GET /v1/usage.
            </p>
        </header>

        <ReportRange :range="range" />

        <div class="grid gap-4">
            <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="tile in tiles"
                    :key="tile.label"
                    class="grid gap-1 rounded-xl border bg-card p-4"
                >
                    <dt class="text-sm text-muted-foreground">
                        {{ tile.label }}
                    </dt>
                    <dd
                        class="font-mono text-2xl tabular-nums"
                        :title="tile.exact"
                    >
                        {{ tile.value }}
                    </dd>
                    <dd class="text-xs text-muted-foreground">
                        {{ tile.hint }}
                    </dd>
                </div>
            </dl>

            <PixelforgeTile
                :days="days"
                :totals="totals"
                :pixelforge="pixelforge"
            />
        </div>

        <section class="grid gap-3">
            <Heading
                variant="small"
                title="Daily spend"
                description="Credit spend per UTC day, oldest on the left: provider cost at the bottom of each bar, profit on top."
            />
            <DailyChart :days="days" />
        </section>

        <section class="grid gap-3">
            <Heading
                variant="small"
                title="By day"
                description="Our provider cost next to Pixelforge's, to the nanodollar. Difference is ours minus Pixelforge's."
            />
            <p class="text-xs text-muted-foreground">
                Both sides bill a generation on the UTC day it completed. Very
                recent generations can lag until their webhook or the minute-ly
                poll lands.
            </p>
            <DaysTable :days="days" :totals="totals" :pixelforge="pixelforge" />
        </section>

        <section class="grid gap-3">
            <Heading
                variant="small"
                title="By user"
                description="Biggest spenders first."
            />
            <UsersTable :users="users" :totals="totals" />
        </section>
    </div>
</template>
