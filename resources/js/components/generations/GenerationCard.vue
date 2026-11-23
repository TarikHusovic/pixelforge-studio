<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import {
    AlertCircle,
    CircleCheck,
    Clock,
    ImageOff,
    LoaderCircle,
    RotateCcw,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { BadgeVariants } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatUsd, formatUsdExact } from '@/lib/money';
import type { Generation, GenerationStatus } from '@/types';

const props = defineProps<{
    generation: Generation;
}>();

defineEmits<{
    retry: [generation: Generation];
}>();

const STATUSES: Record<
    GenerationStatus,
    {
        label: string;
        icon: LucideIcon;
        spin?: boolean;
        variant: BadgeVariants['variant'];
    }
> = {
    pending: {
        label: 'Submitting',
        icon: LoaderCircle,
        spin: true,
        variant: 'secondary',
    },
    queued: { label: 'Queued', icon: Clock, variant: 'secondary' },
    processing: {
        label: 'Processing',
        icon: LoaderCircle,
        spin: true,
        variant: 'secondary',
    },
    succeeded: { label: 'Succeeded', icon: CircleCheck, variant: 'outline' },
    failed: { label: 'Failed', icon: AlertCircle, variant: 'destructive' },
};

const BUSY = 'Pixelforge was busy, and we gave up after retrying.';
const UNREACHABLE = "We couldn't reach Pixelforge.";

/** Keyed by error_code; anything else falls back to the stored message. */
const FAILURE_REASONS: Record<string, string> = {
    generation_failed: "Pixelforge couldn't produce an image for this prompt.",
    internal_error:
        'Pixelforge had an internal error. Trying again usually works.',
    content_policy_violation:
        "Pixelforge's content policy rejected this prompt.",
    daily_limit_exceeded:
        "Pixelforge's daily limit was reached. It resets at 00:00 UTC.",
    rate_limit_exceeded: BUSY,
    concurrency_limit_exceeded: BUSY,
    overloaded: BUSY,
    connection_failed: UNREACHABLE,
    submission_failed: UNREACHABLE,
};

const dateTime = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const status = computed(() => STATUSES[props.generation.status]);
const inFlight = computed(() =>
    ['pending', 'queued', 'processing'].includes(props.generation.status),
);
const failureReason = computed(
    () =>
        FAILURE_REASONS[props.generation.error_code ?? ''] ??
        props.generation.error_message ??
        'Something went wrong.',
);

// "1536x1024" -> "1536 / 1024", so the placeholder and the image take the same space.
const aspectRatio = computed(() => props.generation.size.replace('x', ' / '));
const expired = computed(
    () =>
        props.generation.image_expires_at !== null &&
        new Date(props.generation.image_expires_at).getTime() <= Date.now(),
);
const imageFailed = ref(false);
const showImage = computed(
    () =>
        props.generation.status === 'succeeded' &&
        props.generation.image_url !== null &&
        !expired.value &&
        !imageFailed.value,
);

const referenceCount = computed(() => props.generation.reference_images.length);
</script>

<template>
    <article class="flex flex-col overflow-hidden rounded-xl border bg-card">
        <div class="bg-muted" :style="{ aspectRatio }">
            <a
                v-if="showImage && generation.image_url"
                :href="generation.image_url"
                target="_blank"
                rel="noopener noreferrer"
                class="block size-full"
                title="Open full size in a new tab"
            >
                <img
                    :src="generation.image_url"
                    :alt="generation.prompt"
                    loading="lazy"
                    class="size-full object-cover"
                    @error="imageFailed = true"
                />
            </a>
            <div
                v-else
                class="flex size-full flex-col items-center justify-center gap-2 p-4 text-center text-sm text-muted-foreground"
                :class="{ 'animate-pulse': inFlight }"
            >
                <ImageOff v-if="!inFlight" class="size-6" />
                <span v-if="expired">
                    Image expired — Pixelforge links last 24 hours
                </span>
                <span v-else-if="imageFailed">Couldn't load the image</span>
            </div>
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <Badge :variant="status.variant">
                    <component
                        :is="status.icon"
                        :class="{ 'animate-spin': status.spin }"
                    />
                    {{ status.label }}
                </Badge>
                <span
                    v-if="inFlight"
                    class="text-xs text-muted-foreground tabular-nums"
                >
                    <span
                        :title="formatUsdExact(generation.estimated_cost_nanos)"
                        >Est.
                        {{ formatUsd(generation.estimated_cost_nanos) }}</span
                    >
                    ·
                    <span :title="formatUsdExact(generation.max_cost_nanos)"
                        >up to {{ formatUsd(generation.max_cost_nanos) }}</span
                    >
                </span>
                <span
                    v-else-if="
                        generation.status === 'succeeded' &&
                        generation.cost_nanos !== null
                    "
                    class="text-xs tabular-nums"
                >
                    <span
                        class="font-medium"
                        :title="formatUsdExact(generation.cost_nanos)"
                        >Charged {{ formatUsd(generation.cost_nanos) }}</span
                    >
                    <span
                        class="text-muted-foreground"
                        :title="formatUsdExact(generation.estimated_cost_nanos)"
                    >
                        (est. {{ formatUsd(generation.estimated_cost_nanos) }})
                    </span>
                </span>
                <span v-else class="text-xs text-muted-foreground">
                    Not charged
                </span>
            </div>

            <p
                v-if="generation.status === 'pending' && generation.error_code"
                class="text-xs text-muted-foreground"
            >
                {{
                    generation.error_code === 'connection_failed'
                        ? "Can't reach Pixelforge — retrying"
                        : 'Waiting for Pixelforge — busy, retrying'
                }}
            </p>

            <p class="line-clamp-3 text-sm" :title="generation.prompt">
                {{ generation.prompt }}
            </p>

            <p class="text-xs text-muted-foreground">
                {{ generation.size.replace('x', '×') }} ·
                {{ generation.quality }}
                <template v-if="referenceCount > 0">
                    · {{ referenceCount }} reference
                    {{ referenceCount === 1 ? 'image' : 'images' }}
                </template>
                <br />
                <time :datetime="generation.created_at">
                    {{ dateTime.format(new Date(generation.created_at)) }}
                </time>
            </p>

            <div
                v-if="generation.status === 'failed'"
                class="mt-auto grid gap-2"
            >
                <p class="text-sm text-red-600 dark:text-red-500">
                    {{ failureReason }} You weren't charged.
                </p>
                <Button
                    variant="outline"
                    size="sm"
                    class="w-fit"
                    @click="$emit('retry', generation)"
                >
                    <RotateCcw />
                    Try again
                </Button>
            </div>
        </div>
    </article>
</template>
