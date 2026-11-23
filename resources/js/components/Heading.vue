<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
});
</script>

<template>
    <header :class="variant === 'small' ? 'space-y-1' : 'mb-8 space-y-1.5'">
        <h2
            :class="[
                'font-display leading-tight',
                variant === 'small' ? 'text-lg' : 'text-2xl sm:text-3xl',
            ]"
        >
            {{ title }}
        </h2>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
    </header>
</template>
