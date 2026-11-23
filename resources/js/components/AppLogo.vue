<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
</script>

<template>
    <div
        class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
    >
        <AppLogoIcon class="size-5" />
    </div>
    <div class="ml-1 grid flex-1 text-left leading-none">
        <span class="truncate font-display text-[15px]">Pixelforge</span>
        <span
            class="mt-0.5 truncate text-[10px] font-semibold tracking-[0.22em] text-muted-foreground uppercase"
        >
            Studio
        </span>
    </div>
</template>
