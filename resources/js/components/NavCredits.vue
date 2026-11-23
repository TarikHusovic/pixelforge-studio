<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Wallet } from '@lucide/vue';
import { computed } from 'vue';
import BuyCreditsDialog from '@/components/BuyCreditsDialog.vue';
import { Button } from '@/components/ui/button';
import { formatUsd, formatUsdExact } from '@/lib/money';

const page = usePage();
const credits = computed(() => page.props.credits);
</script>

<template>
    <div
        v-if="credits"
        class="grid gap-2 rounded-md border border-sidebar-border p-3 text-sm group-data-[collapsible=icon]:hidden"
    >
        <div class="flex items-center gap-2">
            <Wallet class="size-4 text-muted-foreground" />
            <span
                class="font-medium tabular-nums"
                :title="formatUsdExact(credits.available_nanos)"
            >
                {{ formatUsd(credits.available_nanos) }} available
            </span>
        </div>
        <p
            v-if="credits.reserved_nanos > 0"
            class="text-xs text-muted-foreground"
            :title="formatUsdExact(credits.reserved_nanos)"
        >
            {{ formatUsd(credits.reserved_nanos) }} held for generations in
            progress
        </p>
        <BuyCreditsDialog>
            <Button variant="outline" size="sm">Buy credits</Button>
        </BuyCreditsDialog>
    </div>
</template>
