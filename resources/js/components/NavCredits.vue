<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Wallet } from '@lucide/vue';
import { computed } from 'vue';
import BuyCreditsDialog from '@/components/BuyCreditsDialog.vue';
import { Button } from '@/components/ui/button';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { formatUsd, formatUsdExact } from '@/lib/money';
import { index as activity } from '@/routes/credits';

const page = usePage();
const credits = computed(() => page.props.credits);
</script>

<template>
    <template v-if="credits">
        <div
            class="grid gap-3 rounded-lg border border-sidebar-border p-3 group-data-[collapsible=icon]:hidden"
        >
            <div class="grid gap-0.5">
                <span class="text-xs text-muted-foreground">Available</span>
                <span
                    class="font-mono text-xl tabular-nums"
                    :title="formatUsdExact(credits.available_nanos)"
                >
                    {{ formatUsd(credits.available_nanos) }}
                </span>
                <span
                    v-if="credits.reserved_nanos > 0"
                    class="text-xs text-muted-foreground"
                    :title="formatUsdExact(credits.reserved_nanos)"
                >
                    {{ formatUsd(credits.reserved_nanos) }} held for generations
                    in progress
                </span>
            </div>
            <BuyCreditsDialog>
                <Button variant="outline">Buy credits</Button>
            </BuyCreditsDialog>
            <Link
                :href="activity()"
                class="justify-self-start text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
            >
                View activity
            </Link>
        </div>

        <!-- The collapsed sidebar has room for an icon only. -->
        <SidebarMenu class="hidden group-data-[collapsible=icon]:flex">
            <SidebarMenuItem>
                <BuyCreditsDialog>
                    <SidebarMenuButton
                        :tooltip="`${formatUsd(credits.available_nanos)} available. Buy credits`"
                    >
                        <Wallet />
                        <span>Buy credits</span>
                    </SidebarMenuButton>
                </BuyCreditsDialog>
            </SidebarMenuItem>
        </SidebarMenu>
    </template>
</template>
