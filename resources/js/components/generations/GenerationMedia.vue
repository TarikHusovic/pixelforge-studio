<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import {
    CircleAlert,
    CircleCheck,
    Clock,
    Flame,
    ImageOff,
    LoaderCircle,
} from '@lucide/vue';
import { useNow } from '@vueuse/core';
import { computed, ref, watchEffect } from 'vue';
import {
    failureReason,
    formatElapsed,
    hasImage,
    isExpired,
    waitingReason,
} from '@/components/generations/display';
import { Badge } from '@/components/ui/badge';
import type { Generation, GenerationStatus } from '@/types';

const props = defineProps<{
    generation: Generation;
}>();

/** Status as temperature: cool ash while waiting, ember while forging, cold red when it failed. */
const STATUSES: Record<
    GenerationStatus,
    { label: string; icon: LucideIcon; class: string }
> = {
    pending: {
        label: 'Submitting',
        icon: LoaderCircle,
        class: 'bg-background/90 text-muted-foreground [&>svg]:animate-spin',
    },
    queued: {
        label: 'Queued',
        icon: Clock,
        class: 'bg-background/90 text-muted-foreground',
    },
    processing: {
        label: 'Forging',
        icon: Flame,
        class: 'bg-ember-soft text-ember [&>svg]:animate-breathe',
    },
    succeeded: {
        label: 'Succeeded',
        icon: CircleCheck,
        class: 'bg-success-soft text-success',
    },
    failed: {
        label: 'Failed',
        icon: CircleAlert,
        class: 'bg-background/90 text-destructive-foreground',
    },
};

const loaded = ref(false);
const broken = ref(false);

// Ticks only while it's being forged, for the elapsed time.
const { now, pause, resume } = useNow({ interval: 1000, controls: true });
watchEffect(() =>
    props.generation.status === 'processing' ? resume() : pause(),
);

const status = computed(() => STATUSES[props.generation.status]);
// "1536x1024" -> "1536 / 1024", so the placeholder and the image take the same space.
const aspectRatio = computed(() => props.generation.size.replace('x', ' / '));
const imageUrl = computed(() =>
    hasImage(props.generation) && !broken.value
        ? props.generation.image_url
        : null,
);

/** What stands in for the image: why it isn't there (yet). */
const placeholder = computed(
    (): { title: string; detail?: string; icon?: LucideIcon } => {
        const generation = props.generation;

        switch (generation.status) {
            case 'pending':
                return {
                    title: waitingReason(generation) ?? 'Sending to Pixelforge',
                };
            case 'queued':
                return {
                    title: 'Queued at Pixelforge',
                    detail: 'Waiting for Pixelforge to start it',
                };
            case 'processing':
                return {
                    title: `Forging · ${formatElapsed(now.value.getTime() - new Date(generation.created_at).getTime())}`,
                    detail: 'Usually 10–30 seconds',
                };
            case 'failed':
                return {
                    title: 'No image',
                    detail: failureReason(generation),
                    icon: CircleAlert,
                };
            case 'succeeded':
                return {
                    title: isExpired(generation)
                        ? 'Image link expired'
                        : "Couldn't load the image",
                    detail: 'Pixelforge image links last 24 hours.',
                    icon: ImageOff,
                };
        }
    },
);
</script>

<template>
    <div class="relative overflow-hidden bg-muted" :style="{ aspectRatio }">
        <img
            v-if="imageUrl"
            :src="imageUrl"
            :alt="generation.prompt"
            loading="lazy"
            class="size-full object-contain"
            :class="loaded ? 'animate-develop' : 'opacity-0'"
            @load="loaded = true"
            @error="broken = true"
        />
        <div
            v-else
            class="flex size-full flex-col items-center justify-center gap-1 p-4 text-center text-sm text-muted-foreground"
            :class="{ 'pixel-grid': generation.in_flight }"
        >
            <div
                v-if="generation.status === 'processing'"
                class="absolute inset-0 animate-heat forge-heat"
                aria-hidden="true"
            />
            <component
                :is="placeholder.icon"
                v-if="placeholder.icon"
                class="mb-1 size-6"
                :class="{
                    'text-destructive-foreground':
                        generation.status === 'failed',
                }"
                aria-hidden="true"
            />
            <p class="relative font-medium text-foreground tabular-nums">
                {{ placeholder.title }}
            </p>
            <p v-if="placeholder.detail" class="relative text-xs">
                {{ placeholder.detail }}
            </p>
        </div>

        <Badge
            variant="outline"
            class="absolute top-2 left-2 border-transparent shadow-xs"
            :class="status.class"
        >
            <component :is="status.icon" aria-hidden="true" />
            {{ status.label }}
        </Badge>
    </div>
</template>
