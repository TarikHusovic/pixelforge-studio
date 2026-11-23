<script setup lang="ts">
import { Head, Link, usePage, usePoll } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, watch } from 'vue';
import BuyCreditsDialog from '@/components/BuyCreditsDialog.vue';
import CreditEntryRow from '@/components/credits/CreditEntryRow.vue';
import { Button } from '@/components/ui/button';
import { formatUsd, formatUsdExact } from '@/lib/money';
import { index } from '@/routes/credits';
import { index as studio } from '@/routes/generations';
import type { CreditEntry, CreditTotals, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Credits', href: index() }],
    },
});

const props = defineProps<{
    entries: Paginated<CreditEntry>;
    totals: CreditTotals;
}>();

const page = usePage();
const available = computed(() => page.props.credits?.available_nanos ?? 0);
const held = computed(() => page.props.credits?.reserved_nanos ?? 0);

// Held lines become charges as generations finish: keep the statement live until then.
const { start, stop } = usePoll(
    3000,
    { only: ['entries', 'totals', 'credits'] },
    { autoStart: false, mode: 'rest' },
);
watch(held, (nanos) => (nanos > 0 ? start() : stop()), { immediate: true });

const tiles = computed(() => [
    {
        label: 'Available',
        nanos: available.value,
        hint: 'What you can spend now',
    },
    {
        label: 'Held',
        nanos: held.value,
        hint: 'The most generations in progress can still cost',
    },
    {
        label: 'Balance',
        nanos: props.totals.balance_nanos,
        hint: `Bought ${formatUsd(props.totals.bought_nanos)}, spent ${formatUsd(props.totals.spent_nanos)}`,
    },
]);

const currentPage = computed(() => props.entries.meta.current_page);
const lastPage = computed(() => props.entries.meta.last_page);
</script>

<template>
    <Head title="Credits" />

    <div class="mx-auto grid w-full max-w-4xl gap-8 p-4">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="grid gap-1">
                <h1 class="font-display text-2xl">Credits</h1>
                <p class="text-sm text-muted-foreground">
                    Every purchase and every charge, newest first. Failed
                    generations are free.
                </p>
            </div>
            <BuyCreditsDialog>
                <Button class="hover:shadow-ember">Buy credits</Button>
            </BuyCreditsDialog>
        </header>

        <dl class="grid gap-4 sm:grid-cols-3">
            <div
                v-for="tile in tiles"
                :key="tile.label"
                class="grid gap-1 rounded-xl border bg-card p-4"
            >
                <dt class="text-sm text-muted-foreground">{{ tile.label }}</dt>
                <dd
                    class="font-mono text-2xl tabular-nums"
                    :title="formatUsdExact(tile.nanos)"
                >
                    {{ formatUsd(tile.nanos) }}
                </dd>
                <dd class="text-xs text-muted-foreground">{{ tile.hint }}</dd>
            </div>
        </dl>

        <section class="grid gap-3" aria-labelledby="activity-heading">
            <h2 id="activity-heading" class="font-display text-lg">Activity</h2>

            <div
                v-if="entries.meta.total === 0"
                class="grid justify-items-center gap-3 rounded-xl border border-dashed bg-grain p-8 text-center"
            >
                <p class="text-sm text-muted-foreground">
                    Nothing here yet. Buy credits, then create in the Studio:
                    every purchase and every charge shows up here.
                </p>
                <Button variant="outline" as-child>
                    <Link :href="studio()">Go to the Studio</Link>
                </Button>
            </div>

            <div v-else class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">
                                Description
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Amount
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <CreditEntryRow
                            v-for="entry in entries.data"
                            :key="`${entry.type}-${entry.id}`"
                            :entry="entry"
                        />
                    </tbody>
                </table>
            </div>

            <nav
                v-if="lastPage > 1"
                class="flex items-center justify-between gap-4"
                aria-label="Pagination"
            >
                <Button v-if="currentPage > 1" variant="outline" as-child>
                    <Link :href="index({ query: { page: currentPage - 1 } })">
                        <ChevronLeft />
                        Newer
                    </Link>
                </Button>
                <span v-else />
                <span class="text-sm text-muted-foreground">
                    Page {{ currentPage }} of {{ lastPage }}
                </span>
                <Button
                    v-if="currentPage < lastPage"
                    variant="outline"
                    as-child
                >
                    <Link :href="index({ query: { page: currentPage + 1 } })">
                        Older
                        <ChevronRight />
                    </Link>
                </Button>
                <span v-else />
            </nav>
        </section>
    </div>
</template>
