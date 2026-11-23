<script setup lang="ts">
import PixelforgeCells from '@/components/admin/PixelforgeCells.vue';
import type {
    Amounts,
    PixelforgeReport,
    ReportDay,
} from '@/components/admin/report';
import { formatUsdExact } from '@/lib/money';

defineProps<{
    /** Newest first, every day of the range, zeros included, so the rows line up with Pixelforge's. */
    days: ReportDay[];
    totals: Amounts;
    pixelforge?: PixelforgeReport;
}>();
</script>

<template>
    <div class="max-h-[70vh] overflow-auto rounded-xl border bg-card">
        <table class="w-full text-sm whitespace-nowrap tabular-nums">
            <thead
                class="sticky top-0 z-10 border-b bg-muted text-muted-foreground"
            >
                <tr>
                    <th class="px-3 py-2 text-left font-medium">Day (UTC)</th>
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
                    <th class="px-3 py-2 text-left font-medium">Difference</th>
                    <th class="px-3 py-2 text-right font-medium">Profit</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="day in days"
                    :key="day.date"
                    class="border-b transition-colors last:border-0 hover:bg-muted/40"
                    :class="{ 'text-muted-foreground': day.generations === 0 }"
                >
                    <th scope="row" class="px-3 py-2 text-left font-normal">
                        {{ day.date }}
                    </th>
                    <td class="px-3 py-2 text-right">{{ day.generations }}</td>
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
</template>
