<script setup lang="ts">
import { Copy, Download, Maximize2, Repeat2, RotateCcw } from '@lucide/vue';
import { useTimeAgo } from '@vueuse/core';
import { computed } from 'vue';
import {
    copyPrompt,
    formatDateTime,
    hasImage,
} from '@/components/generations/display';
import GenerationMedia from '@/components/generations/GenerationMedia.vue';
import IconButton from '@/components/generations/IconButton.vue';
import { Button } from '@/components/ui/button';
import { formatUsd, formatUsdExact } from '@/lib/money';
import { download } from '@/routes/generations';
import type { Generation } from '@/types';

const props = defineProps<{
    generation: Generation;
}>();

defineEmits<{
    /** Put its settings back in the composer. */
    reuse: [generation: Generation];
    open: [generation: Generation];
}>();

const createdAgo = useTimeAgo(() => props.generation.created_at);
const failed = computed(() => props.generation.status === 'failed');
const imageAvailable = computed(() => hasImage(props.generation));

/** "1024×1024 · medium · 2 refs" */
const settings = computed(() => {
    const { size, quality, reference_images: references } = props.generation;
    const parts = [size.replace('x', '×'), quality];

    if (references.length > 0) {
        parts.push(
            `${references.length} ${references.length === 1 ? 'ref' : 'refs'}`,
        );
    }

    return parts.join(' · ');
});
</script>

<template>
    <!-- Named by its prompt, so "Copy prompt" and friends say which card they're on. -->
    <article
        class="flex flex-col overflow-hidden rounded-xl border bg-card"
        :aria-labelledby="`prompt-${generation.id}`"
    >
        <button
            v-if="imageAvailable"
            type="button"
            class="block cursor-zoom-in outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:ring-inset"
            aria-label="View details"
            @click="$emit('open', generation)"
        >
            <GenerationMedia :generation="generation" />
        </button>
        <GenerationMedia v-else :generation="generation" />

        <div class="flex flex-1 flex-col gap-2 p-3">
            <p
                :id="`prompt-${generation.id}`"
                class="line-clamp-2 text-sm"
                :title="generation.prompt"
            >
                {{ generation.prompt }}
            </p>

            <p class="text-xs text-muted-foreground">
                {{ settings }} ·
                <time
                    :datetime="generation.created_at"
                    :title="formatDateTime(generation.created_at)"
                    >{{ createdAgo }}</time
                >
            </p>

            <p class="text-xs">
                <template v-if="generation.in_flight">
                    Est.
                    <span
                        class="font-mono tabular-nums"
                        :title="formatUsdExact(generation.estimated_cost_nanos)"
                        >{{ formatUsd(generation.estimated_cost_nanos) }}</span
                    >
                    <span class="text-muted-foreground">
                        · up to
                        <span
                            class="font-mono tabular-nums"
                            :title="formatUsdExact(generation.max_cost_nanos)"
                            >{{ formatUsd(generation.max_cost_nanos) }}</span
                        >
                        held
                    </span>
                </template>
                <template
                    v-else-if="
                        generation.status === 'succeeded' &&
                        generation.cost_nanos !== null
                    "
                >
                    <span class="font-medium">
                        Charged
                        <span
                            class="font-mono tabular-nums"
                            :title="formatUsdExact(generation.cost_nanos)"
                            >{{ formatUsd(generation.cost_nanos) }}</span
                        >
                    </span>
                    <span class="text-muted-foreground">
                        · est.
                        <span
                            class="font-mono tabular-nums"
                            :title="
                                formatUsdExact(generation.estimated_cost_nanos)
                            "
                            >{{
                                formatUsd(generation.estimated_cost_nanos)
                            }}</span
                        >
                    </span>
                </template>
                <span v-else class="text-muted-foreground">Not charged</span>
            </p>

            <div class="mt-auto flex items-center gap-1 pt-1">
                <Button
                    variant="outline"
                    class="me-auto"
                    :aria-label="failed ? undefined : 'Reuse settings'"
                    @click="$emit('reuse', generation)"
                >
                    <component :is="failed ? RotateCcw : Repeat2" />
                    {{ failed ? 'Try again' : 'Reuse' }}
                </Button>
                <IconButton
                    label="Copy prompt"
                    @click="copyPrompt(generation.prompt)"
                >
                    <Copy />
                </IconButton>
                <IconButton
                    v-if="imageAvailable"
                    label="Download"
                    as="a"
                    :href="download.url(generation)"
                    download
                >
                    <Download />
                </IconButton>
                <IconButton label="Details" @click="$emit('open', generation)">
                    <Maximize2 />
                </IconButton>
            </div>
        </div>
    </article>
</template>
