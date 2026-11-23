<script setup lang="ts">
import { Image, ImageOff } from '@lucide/vue';
import { useDebounce } from '@vueuse/core';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    url: string;
}>();

// Trails the typing, so we don't fetch every half-typed URL.
const src = useDebounce(
    computed(() => {
        const url = props.url.trim();

        return /^https?:/i.test(url) && URL.canParse(url) ? url : null;
    }),
    600,
);
const failed = ref(false);

watch(src, () => (failed.value = false));
</script>

<template>
    <span
        class="grid size-9 shrink-0 place-items-center overflow-hidden rounded-md border bg-muted pixel-grid text-muted-foreground"
    >
        <img
            v-if="src && !failed"
            :src="src"
            alt=""
            class="size-full object-cover"
            referrerpolicy="no-referrer"
            @error="failed = true"
        />
        <template v-else-if="src">
            <ImageOff class="size-4" />
            <span class="sr-only">No preview for this URL</span>
        </template>
        <Image v-else class="size-4" />
    </span>
</template>
