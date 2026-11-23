<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CirclePlus, CircleX, Hourglass, Receipt } from '@lucide/vue';
import { computed } from 'vue';
import { formatUsd, formatUsdExact } from '@/lib/money';
import { index as studio } from '@/routes/generations';
import type { CreditEntry, Nanos } from '@/types';

const props = defineProps<{
    entry: CreditEntry;
}>();

const LINES = {
    purchase: {
        label: 'Bought credits',
        icon: CirclePlus,
        iconClass: 'text-success',
        amountClass: 'text-success',
    },
    charged: {
        label: 'Charged',
        icon: Receipt,
        iconClass: 'text-muted-foreground',
        amountClass: '',
    },
    held: {
        label: "Held until it's done",
        icon: Hourglass,
        iconClass: 'text-ember animate-breathe',
        amountClass: '',
    },
    failed: {
        label: 'Failed — not charged',
        icon: CircleX,
        iconClass: '',
        amountClass: '',
    },
};

const dateTime = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const kind = computed<keyof typeof LINES>(() => {
    if (props.entry.type === 'purchase') {
        return 'purchase';
    }

    if (props.entry.status === 'succeeded') {
        return 'charged';
    }

    return props.entry.status === 'failed' ? 'failed' : 'held';
});
const line = computed(() => LINES[kind.value]);

/** "+$10.00", "−$0.0800", "$0.00": the sign says which way the money went. */
function signedUsd(nanos: Nanos): string {
    if (nanos > 0) {
        return `+${formatUsd(nanos)}`;
    }

    return nanos < 0 ? `−${formatUsd(-nanos)}` : formatUsd(0);
}
</script>

<template>
    <tr
        class="border-b last:border-0"
        :class="{ 'text-muted-foreground': kind === 'failed' }"
    >
        <td class="w-full max-w-0 px-3 py-3">
            <div class="flex items-start gap-3">
                <component
                    :is="line.icon"
                    class="mt-0.5 size-4 shrink-0"
                    :class="line.iconClass"
                    aria-hidden="true"
                />
                <div class="grid min-w-0 gap-0.5">
                    <span class="font-medium">{{ line.label }}</span>
                    <Link
                        v-if="entry.prompt !== null"
                        :href="studio({ query: { search: entry.prompt } })"
                        class="truncate text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                        :title="`Find “${entry.prompt}” in the Studio`"
                    >
                        {{ entry.prompt }}
                    </Link>
                    <time
                        class="text-xs text-muted-foreground"
                        :datetime="entry.created_at"
                    >
                        {{ dateTime.format(new Date(entry.created_at)) }}
                    </time>
                </div>
            </div>
        </td>
        <td
            class="px-3 py-3 text-right align-top font-mono whitespace-nowrap tabular-nums"
            :class="line.amountClass"
            :title="formatUsdExact(entry.amount_nanos)"
        >
            {{ signedUsd(entry.amount_nanos) }}
        </td>
    </tr>
</template>
