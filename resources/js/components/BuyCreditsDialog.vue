<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { store } from '@/actions/App/Http/Controllers/CreditPurchaseController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { formatUsd, formatUsdExact, NANOS_PER_DOLLAR } from '@/lib/money';
import type { Nanos } from '@/types';

const props = defineProps<{
    /** What one image costs at the composer's current settings: each pack then says how many it buys. */
    perImageNanos?: Nanos;
}>();

const emit = defineEmits<{
    purchased: [];
}>();

const page = usePage();
const amounts = computed(() => page.props.credits?.purchase_amounts ?? []);
const available = computed(() => page.props.credits?.available_nanos ?? 0);

const open = ref(false);
const buying = ref(false);
const error = ref<string>();
// The middle pack to start with.
const amount = ref(amounts.value[Math.floor(amounts.value.length / 2)]);

const availableAfter = computed(
    () => available.value + (amount.value ?? 0) * NANOS_PER_DOLLAR,
);

function imagesFor(dollars: number): string | null {
    if (!props.perImageNanos) {
        return null;
    }

    const count = Math.floor(
        (dollars * NANOS_PER_DOLLAR) / props.perImageNanos,
    );

    return `≈ ${count} ${count === 1 ? 'image' : 'images'} like this`;
}

function buy() {
    router.post(
        store(),
        { amount: amount.value },
        {
            preserveScroll: true,
            onStart: () => {
                buying.value = true;
                error.value = undefined;
            },
            onSuccess: () => {
                open.value = false;
                emit('purchased');
            },
            onError: (errors) => (error.value = errors.amount),
            onFinish: () => (buying.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent class="sm:max-w-md">
            <form class="grid gap-6" @submit.prevent="buy">
                <DialogHeader>
                    <DialogTitle>Buy credits</DialogTitle>
                    <DialogDescription>
                        Credits are US dollars, spent as you generate. This is a
                        demo: no payment is taken, credits are added right away.
                    </DialogDescription>
                </DialogHeader>

                <fieldset>
                    <legend class="mb-2 text-sm font-medium">Amount</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <label
                            v-for="dollars in amounts"
                            :key="dollars"
                            class="grid cursor-pointer content-start gap-1 rounded-lg border bg-background p-3 text-center transition-colors hover:border-foreground/30 has-checked:border-foreground/70 has-checked:bg-accent has-checked:inset-ring has-checked:inset-ring-foreground/70 has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                        >
                            <input
                                v-model="amount"
                                type="radio"
                                name="amount"
                                class="sr-only"
                                :value="dollars"
                            />
                            <span class="font-mono text-lg tabular-nums">
                                ${{ dollars }}
                            </span>
                            <span
                                v-if="imagesFor(dollars)"
                                class="text-xs text-muted-foreground"
                            >
                                {{ imagesFor(dollars) }}
                            </span>
                        </label>
                    </div>
                </fieldset>

                <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
                    <dt class="text-muted-foreground">Available now</dt>
                    <dd
                        class="text-right font-mono tabular-nums"
                        :title="formatUsdExact(available)"
                    >
                        {{ formatUsd(available) }}
                    </dd>
                    <dt class="text-muted-foreground">Available after</dt>
                    <dd
                        class="text-right font-mono font-medium tabular-nums"
                        :title="formatUsdExact(availableAfter)"
                    >
                        {{ formatUsd(availableAfter) }}
                    </dd>
                </dl>

                <InputError :message="error" />

                <DialogFooter>
                    <Button
                        type="submit"
                        class="hover:shadow-ember"
                        :disabled="buying || amount === undefined"
                    >
                        <Spinner v-if="buying" />
                        Add ${{ amount }} of credits
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
