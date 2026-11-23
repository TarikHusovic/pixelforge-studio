<script setup lang="ts">
import type { LinkComponentBaseProps, Method } from '@inertiajs/core';
import { Link } from '@inertiajs/vue3';

type Props = {
    href: LinkComponentBaseProps['href'];
    method?: Method;
    as?: string;
};

defineProps<Props>();
</script>

<template>
    <Link
        :href="href"
        :method="method"
        :as="as"
        class="rounded-sm text-foreground underline decoration-foreground/30 underline-offset-4 transition-colors hover:decoration-current"
    >
        <slot />
    </Link>
</template>
