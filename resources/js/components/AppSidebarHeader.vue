<script setup lang="ts">
import AppearanceMenu from '@/components/AppearanceMenu.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
</script>

<template>
    <!-- Sticky, so the studio's sticky composer can sit right under it at any scroll position. -->
    <header
        class="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-3 border-b bg-background/85 px-4 backdrop-blur md:rounded-t-xl md:px-6"
    >
        <SidebarTrigger class="-ml-1 size-8" />
        <template v-if="breadcrumbs.length > 0">
            <Separator
                orientation="vertical"
                class="data-[orientation=vertical]:h-4"
            />
            <Breadcrumbs :breadcrumbs="breadcrumbs" class="min-w-0" />
        </template>
        <div class="ml-auto">
            <AppearanceMenu />
        </div>
    </header>
</template>
