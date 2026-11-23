<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { store } from '@/actions/App/Http/Controllers/CreditPurchaseController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

const page = usePage();
const open = ref(false);
const buying = ref<number | null>(null);

function buy(amount: number) {
    router.post(
        store(),
        { amount },
        {
            preserveScroll: true,
            onStart: () => (buying.value = amount),
            onSuccess: () => (open.value = false),
            onFinish: () => (buying.value = null),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>Buy credits</DialogTitle>
                <DialogDescription>
                    Credits are US dollars, spent as you generate. This is a
                    demo: no payment is taken, credits are added right away.
                </DialogDescription>
            </DialogHeader>
            <div class="grid grid-cols-3 gap-2">
                <Button
                    v-for="amount in page.props.credits?.purchase_amounts"
                    :key="amount"
                    variant="outline"
                    :disabled="buying !== null"
                    @click="buy(amount)"
                >
                    <Spinner v-if="buying === amount" />
                    ${{ amount }}
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
