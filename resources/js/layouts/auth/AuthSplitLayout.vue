<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Eye, Scale, ShieldCheck } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

const valueProps = [
    { Icon: Eye, text: 'You see the price before you generate.' },
    {
        Icon: Scale,
        text: "You pay Pixelforge's cost + 25%, to the nanodollar.",
    },
    { Icon: ShieldCheck, text: 'Failed generations are free.' },
];

// Decorative echo of the logo: cool pixels ('x') and two being forged ('o').
const mosaic = ['xx.xx', 'xx.xx', '.....', 'xx.xo', 'xx.ox'];
const pixelClass: Record<string, string> = {
    x: 'bg-foreground/15',
    o: 'bg-ember animate-breathe',
    '.': '',
};
</script>

<template>
    <div class="grid min-h-svh lg:grid-cols-[5fr_6fr]">
        <!-- Always soot, whatever the theme: `.dark` scopes the dark tokens to this panel. -->
        <aside
            class="dark relative hidden overflow-hidden bg-background bg-grain text-foreground lg:flex lg:flex-col lg:justify-between lg:p-12 xl:p-16"
        >
            <div
                aria-hidden="true"
                class="absolute inset-0 pixel-grid opacity-50"
            />
            <div
                aria-hidden="true"
                class="absolute -bottom-40 -left-32 size-[32rem] rounded-full bg-ember/20 blur-3xl"
            />

            <Link
                :href="home()"
                class="relative flex w-fit items-center gap-2 rounded-md"
            >
                <AppLogo />
            </Link>

            <div
                aria-hidden="true"
                class="relative grid w-fit grid-cols-5 gap-1.5 self-end"
            >
                <template v-for="(row, r) in mosaic" :key="r">
                    <span
                        v-for="(cell, c) in row"
                        :key="c"
                        :class="['size-5 rounded-[3px]', pixelClass[cell]]"
                    />
                </template>
            </div>

            <div class="relative max-w-md space-y-8">
                <p class="font-display text-4xl leading-tight xl:text-5xl">
                    Forge images from words.
                </p>
                <ul class="space-y-4">
                    <li
                        v-for="{ Icon, text } in valueProps"
                        :key="text"
                        class="flex items-start gap-3 text-muted-foreground"
                    >
                        <component
                            :is="Icon"
                            class="mt-0.5 size-5 shrink-0 text-ember"
                        />
                        <span>{{ text }}</span>
                    </li>
                </ul>
            </div>
        </aside>

        <main class="flex items-center justify-center px-6 py-12 sm:px-10">
            <div class="w-full max-w-sm space-y-8">
                <Link
                    :href="home()"
                    class="flex w-fit items-center gap-2 rounded-md lg:hidden"
                >
                    <AppLogo />
                </Link>

                <div v-if="title || description" class="space-y-2">
                    <h1
                        v-if="title"
                        class="font-display text-3xl leading-tight"
                    >
                        {{ title }}
                    </h1>
                    <p v-if="description" class="text-sm text-muted-foreground">
                        {{ description }}
                    </p>
                </div>

                <div>
                    <slot />
                </div>
            </div>
        </main>
    </div>
</template>
