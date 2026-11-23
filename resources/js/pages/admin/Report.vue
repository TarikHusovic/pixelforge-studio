<script setup lang="ts">
import { Deferred, Form, Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import PixelforgeCells from '@/components/admin/PixelforgeCells.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { formatUsd, formatUsdExact } from '@/lib/money';
import { report } from '@/routes/admin';
import type { Nanos } from '@/types';

/** Succeeded generations only, on the UTC day they completed. */
type Amounts = {
    generations: number;
    spend_nanos: Nanos;
    provider_cost_nanos: Nanos;
    profit_nanos: Nanos;
};

type PixelforgeUsage = { generations: number; cost_nanos: Nanos };

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin report', href: report() }],
    },
});

const props = defineProps<{
    /** Inclusive UTC days, after defaults and the 92-day limit. */
    range: { from: string; to: string };
    days: (Amounts & { date: string })[];
    totals: Amounts;
    users: (Amounts & { id: number; name: string; email: string })[];
    /** Pixelforge's GET /v1/usage for the same days. Deferred; missing if Pixelforge failed. */
    pixelforge?: {
        days: Record<string, PixelforgeUsage>;
        total: PixelforgeUsage;
    };
}>();

const stats = computed(() => [
    {
        label: 'Credit spend',
        value: formatUsd(props.totals.spend_nanos),
        hint: `What users were charged, ${props.totals.generations} generations`,
    },
    {
        label: 'Provider cost',
        value: formatUsd(props.totals.provider_cost_nanos),
        hint: 'What Pixelforge charged us, by our records',
    },
    {
        label: 'Profit',
        value: formatUsd(props.totals.profit_nanos),
        hint: 'Credit spend minus provider cost',
    },
    {
        label: 'Margin',
        value:
            props.totals.spend_nanos === 0
                ? '—'
                : `${((props.totals.profit_nanos / props.totals.spend_nanos) * 100).toFixed(1)}%`,
        hint: 'Profit as a share of credit spend',
    },
]);
</script>

<template>
    <Head title="Admin report" />

    <div class="flex flex-col gap-8 p-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <Heading
                title="Admin report"
                description="Credit spend, provider cost and profit per UTC day and per user."
            />

            <Form v-bind="report.form()" class="flex flex-wrap items-end gap-3">
                <div class="grid gap-1.5">
                    <Label for="from">From (UTC)</Label>
                    <Input
                        id="from"
                        name="from"
                        type="date"
                        :default-value="range.from"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="to">To (UTC)</Label>
                    <Input
                        id="to"
                        name="to"
                        type="date"
                        :default-value="range.to"
                    />
                </div>
                <Button type="submit" variant="secondary">Show</Button>
                <p class="w-full text-xs text-muted-foreground">
                    Up to 92 days at a time, like Pixelforge's usage report.
                </p>
            </Form>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <Card v-for="stat in stats" :key="stat.label" class="gap-2 py-4">
                <CardHeader class="px-4">
                    <CardDescription>{{ stat.label }}</CardDescription>
                    <CardTitle class="text-2xl tabular-nums">
                        {{ stat.value }}
                    </CardTitle>
                    <p class="text-xs text-muted-foreground">{{ stat.hint }}</p>
                </CardHeader>
            </Card>

            <Card class="gap-2 py-4">
                <CardHeader class="px-4">
                    <CardDescription>Pixelforge reports</CardDescription>
                    <Deferred data="pixelforge">
                        <template #fallback>
                            <Skeleton class="h-7 w-28" />
                            <Skeleton class="h-4 w-36" />
                        </template>
                        <template #rescue>
                            <CardTitle class="text-destructive">
                                Couldn't reach Pixelforge
                            </CardTitle>
                            <p class="text-xs text-muted-foreground">
                                Reload the page to try again.
                            </p>
                        </template>
                        <template v-if="pixelforge">
                            <CardTitle class="text-2xl tabular-nums">
                                {{ formatUsd(pixelforge.total.cost_nanos) }}
                            </CardTitle>
                            <p class="text-xs text-muted-foreground">
                                GET /v1/usage,
                                {{ pixelforge.total.generations }} generations
                            </p>
                        </template>
                    </Deferred>
                </CardHeader>
            </Card>
        </div>

        <section class="flex flex-col gap-3">
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

            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm whitespace-nowrap tabular-nums">
                    <thead class="border-b bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">Day</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Generations
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Credit spend
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Provider cost
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Pixelforge cost
                            </th>
                            <th class="px-3 py-2 text-left font-medium">
                                Difference
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="day in days"
                            :key="day.date"
                            class="border-b last:border-0"
                        >
                            <td class="px-3 py-2">{{ day.date }}</td>
                            <td class="px-3 py-2 text-right">
                                {{ day.generations }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono">
                                {{ formatUsdExact(day.spend_nanos) }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono">
                                {{ formatUsdExact(day.provider_cost_nanos) }}
                            </td>
                            <PixelforgeCells
                                :ours="day.provider_cost_nanos"
                                :theirs="pixelforge?.days[day.date]?.cost_nanos"
                            />
                            <td class="px-3 py-2 text-right font-mono">
                                {{ formatUsdExact(day.profit_nanos) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t bg-muted/50 font-medium">
                        <tr>
                            <td class="px-3 py-2">Total</td>
                            <td class="px-3 py-2 text-right">
                                {{ totals.generations }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono">
                                {{ formatUsdExact(totals.spend_nanos) }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono">
                                {{ formatUsdExact(totals.provider_cost_nanos) }}
                            </td>
                            <PixelforgeCells
                                :ours="totals.provider_cost_nanos"
                                :theirs="pixelforge?.total.cost_nanos"
                            />
                            <td class="px-3 py-2 text-right font-mono">
                                {{ formatUsdExact(totals.profit_nanos) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <section class="flex flex-col gap-3">
            <Heading
                variant="small"
                title="By user"
                description="Biggest spenders first."
            />

            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm whitespace-nowrap tabular-nums">
                    <thead class="border-b bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">
                                User
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Generations
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Credit spend
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Provider cost
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="user in users"
                            :key="user.id"
                            class="border-b last:border-0"
                        >
                            <td class="px-3 py-2">
                                {{ user.name }}
                                <span class="text-muted-foreground">
                                    {{ user.email }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ user.generations }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono">
                                {{ formatUsdExact(user.spend_nanos) }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono">
                                {{ formatUsdExact(user.provider_cost_nanos) }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono">
                                {{ formatUsdExact(user.profit_nanos) }}
                            </td>
                        </tr>
                        <tr v-if="users.length === 0">
                            <td
                                colspan="5"
                                class="px-3 py-6 text-center text-muted-foreground"
                            >
                                No generations completed in this range.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
