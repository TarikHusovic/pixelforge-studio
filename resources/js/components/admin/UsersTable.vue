<script setup lang="ts">
import type { Amounts, ReportUser } from '@/components/admin/report';
import { formatUsdExact } from '@/lib/money';

const props = defineProps<{
    /** Biggest spenders first. */
    users: ReportUser[];
    totals: Amounts;
}>();

const percent = new Intl.NumberFormat('en-US', {
    style: 'percent',
    maximumFractionDigits: 1,
});

/** The user's share of the range's credit spend, from 0 to 1. For display only. */
function share(user: ReportUser): number {
    return props.totals.spend_nanos > 0
        ? user.spend_nanos / props.totals.spend_nanos
        : 0;
}
</script>

<template>
    <p
        v-if="users.length === 0"
        class="rounded-xl border border-dashed bg-grain p-8 text-center text-sm text-muted-foreground"
    >
        No one was billed in this range. Users show up here once one of their
        generations succeeds.
    </p>

    <div v-else class="max-h-[70vh] overflow-auto rounded-xl border bg-card">
        <table class="w-full text-sm whitespace-nowrap tabular-nums">
            <thead
                class="sticky top-0 z-10 border-b bg-muted text-muted-foreground"
            >
                <tr>
                    <th class="px-3 py-2 text-left font-medium">User</th>
                    <th class="px-3 py-2 text-right font-medium">
                        Generations
                    </th>
                    <th class="px-3 py-2 text-right font-medium">
                        Credit spend
                    </th>
                    <th class="px-3 py-2 text-left font-medium">
                        Share of spend
                    </th>
                    <th class="px-3 py-2 text-right font-medium">
                        Provider cost
                    </th>
                    <th class="px-3 py-2 text-right font-medium">Profit</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="user in users"
                    :key="user.id"
                    class="border-b transition-colors last:border-0 hover:bg-muted/40"
                >
                    <th scope="row" class="px-3 py-2 text-left font-normal">
                        <span class="block font-medium">{{ user.name }}</span>
                        <span class="block text-xs text-muted-foreground">
                            {{ user.email }}
                        </span>
                    </th>
                    <td class="px-3 py-2 text-right">{{ user.generations }}</td>
                    <td class="px-3 py-2 text-right font-mono">
                        {{ formatUsdExact(user.spend_nanos) }}
                    </td>
                    <td class="px-3 py-2">
                        <div class="flex items-center gap-2">
                            <span
                                class="h-1.5 w-20 overflow-hidden rounded-full bg-foreground/10"
                                aria-hidden="true"
                            >
                                <span
                                    class="block h-full rounded-full bg-foreground/60"
                                    :style="{ width: `${share(user) * 100}%` }"
                                />
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{ percent.format(share(user)) }}
                            </span>
                        </div>
                    </td>
                    <td class="px-3 py-2 text-right font-mono">
                        {{ formatUsdExact(user.provider_cost_nanos) }}
                    </td>
                    <td class="px-3 py-2 text-right font-mono">
                        {{ formatUsdExact(user.profit_nanos) }}
                    </td>
                </tr>
            </tbody>
            <tfoot class="sticky bottom-0 border-t bg-muted">
                <tr class="font-medium">
                    <th scope="row" class="px-3 py-2 text-left font-semibold">
                        Total
                    </th>
                    <td class="px-3 py-2 text-right">
                        {{ totals.generations }}
                    </td>
                    <td class="px-3 py-2 text-right font-mono">
                        {{ formatUsdExact(totals.spend_nanos) }}
                    </td>
                    <td />
                    <td class="px-3 py-2 text-right font-mono">
                        {{ formatUsdExact(totals.provider_cost_nanos) }}
                    </td>
                    <td class="px-3 py-2 text-right font-mono">
                        {{ formatUsdExact(totals.profit_nanos) }}
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</template>
