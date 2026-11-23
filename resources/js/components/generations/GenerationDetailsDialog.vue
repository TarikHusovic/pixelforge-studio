<script setup lang="ts">
import {
    ChevronLeft,
    ChevronRight,
    Copy,
    Download,
    ExternalLink,
    ImagePlus,
    Repeat2,
    RotateCcw,
} from '@lucide/vue';
import { onKeyStroke, useTimeAgo } from '@vueuse/core';
import { computed, ref, watch, watchEffect } from 'vue';
import {
    copyPrompt,
    failureReason,
    formatDateTime,
    hasImage,
    isExpired,
} from '@/components/generations/display';
import GenerationMedia from '@/components/generations/GenerationMedia.vue';
import IconButton from '@/components/generations/IconButton.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatUsd, formatUsdExact } from '@/lib/money';
import { download } from '@/routes/generations';
import type { Generation } from '@/types';

const props = defineProps<{
    /** The current page, for previous / next. */
    generations: Generation[];
}>();

const emit = defineEmits<{
    reuse: [generation: Generation];
    useAsReference: [url: string];
}>();

/** The id of the generation on show; null while closed. */
const selectedId = defineModel<string | null>({ required: true });

const selected = computed(
    () => props.generations.find(({ id }) => id === selectedId.value) ?? null,
);

// Keeps the last one on screen while the dialog animates closed.
const generation = ref<Generation | null>(null);
watch(
    selected,
    (value) => {
        if (value) {
            generation.value = value;
        }
    },
    { immediate: true },
);

// From the one on screen, so the counter doesn't flash "0 of 12" while closing.
const position = computed(() =>
    props.generations.findIndex(({ id }) => id === generation.value?.id),
);

const previous = computed(() =>
    selected.value ? props.generations[position.value - 1] : undefined,
);
const next = computed(() =>
    selected.value ? props.generations[position.value + 1] : undefined,
);

// The open generation left the list (another tab, or it finished on "In progress"): close,
// rather than pop open again whenever it comes back.
watchEffect(() => {
    if (selectedId.value !== null && !selected.value) {
        selectedId.value = null;
    }
});

const imageAvailable = computed(
    () => generation.value !== null && hasImage(generation.value),
);
const expiry = useTimeAgo(() => generation.value?.image_expires_at ?? 0);
const tookSeconds = computed(() =>
    generation.value?.completed_at
        ? Math.round(
              (new Date(generation.value.completed_at).getTime() -
                  new Date(generation.value.created_at).getTime()) /
                  1000,
          )
        : null,
);

function show(target: Generation | undefined) {
    if (target) {
        selectedId.value = target.id;
    }
}

// Reusing hands focus to the composer. That has to wait until the dialog has closed: until
// then its focus trap would pull focus straight back.
let reusing: Generation | null = null;

function reuse(target: Generation) {
    reusing = target;
    selectedId.value = null;
}

function onCloseAutoFocus(event: Event) {
    if (reusing) {
        event.preventDefault();
        emit('reuse', reusing);
        reusing = null;
    }
}

function onOpenChange(open: boolean) {
    if (!open) {
        selectedId.value = null;
    }
}

/** ←/→ step through the page; leave Alt+← (back) and friends to the browser. */
function step(event: KeyboardEvent, target: Generation | undefined) {
    if (!(event.altKey || event.ctrlKey || event.metaKey || event.shiftKey)) {
        show(target);
    }
}

onKeyStroke('ArrowLeft', (event) => step(event, previous.value));
onKeyStroke('ArrowRight', (event) => step(event, next.value));
</script>

<template>
    <Dialog :open="selected !== null" @update:open="onOpenChange">
        <DialogContent
            class="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-5xl"
            @close-auto-focus="onCloseAutoFocus"
        >
            <div
                v-if="generation"
                class="grid gap-6 md:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]"
            >
                <GenerationMedia
                    :key="generation.id"
                    :generation="generation"
                    class="max-h-[60vh] rounded-lg md:max-h-[80vh]"
                />

                <div class="grid min-w-0 content-start gap-5 text-sm">
                    <div class="grid gap-1 pe-8">
                        <DialogTitle class="font-display text-xl font-normal">
                            Generation details
                        </DialogTitle>
                        <DialogDescription>
                            Created {{ formatDateTime(generation.created_at) }}
                        </DialogDescription>
                    </div>

                    <p
                        v-if="generation.status === 'failed'"
                        class="text-destructive-foreground"
                    >
                        {{ failureReason(generation) }} Not charged.
                    </p>

                    <section
                        class="grid gap-1"
                        aria-labelledby="details-prompt"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <h3 id="details-prompt" class="font-medium">
                                Prompt
                            </h3>
                            <IconButton
                                label="Copy prompt"
                                @click="copyPrompt(generation.prompt)"
                            >
                                <Copy />
                            </IconButton>
                        </div>
                        <p
                            class="max-h-40 overflow-y-auto break-words whitespace-pre-wrap"
                        >
                            {{ generation.prompt }}
                        </p>
                    </section>

                    <dl
                        class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1"
                    >
                        <dt class="text-muted-foreground">Size</dt>
                        <dd>{{ generation.size.replace('x', '×') }}</dd>
                        <dt class="text-muted-foreground">Quality</dt>
                        <dd class="capitalize">{{ generation.quality }}</dd>
                        <dt class="text-muted-foreground">References</dt>
                        <dd>
                            <span
                                v-if="generation.reference_images.length === 0"
                            >
                                None
                            </span>
                            <ul v-else class="grid gap-0.5">
                                <li
                                    v-for="url in generation.reference_images"
                                    :key="url"
                                    class="truncate"
                                >
                                    <a
                                        :href="url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="underline underline-offset-4"
                                        >{{ url }}</a
                                    >
                                </li>
                            </ul>
                        </dd>
                    </dl>

                    <section class="grid gap-2" aria-labelledby="details-cost">
                        <h3 id="details-cost" class="font-medium">Cost</h3>
                        <dl
                            class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 rounded-lg border p-3"
                        >
                            <dt class="text-muted-foreground">Estimated</dt>
                            <dd
                                class="text-right font-mono tabular-nums"
                                :title="
                                    formatUsdExact(
                                        generation.estimated_cost_nanos,
                                    )
                                "
                            >
                                {{ formatUsd(generation.estimated_cost_nanos) }}
                            </dd>
                            <dt class="text-muted-foreground">
                                Held until it's done
                            </dt>
                            <dd
                                class="text-right font-mono tabular-nums"
                                :title="
                                    formatUsdExact(generation.max_cost_nanos)
                                "
                            >
                                {{ formatUsd(generation.max_cost_nanos) }}
                                <span
                                    v-if="!generation.in_flight"
                                    class="font-sans text-muted-foreground"
                                    >· released</span
                                >
                            </dd>
                            <dt class="font-medium">Charged</dt>
                            <dd class="text-right font-medium">
                                <span
                                    v-if="
                                        generation.status === 'succeeded' &&
                                        generation.cost_nanos !== null
                                    "
                                    class="font-mono tabular-nums"
                                    :title="
                                        formatUsdExact(generation.cost_nanos)
                                    "
                                    >{{
                                        formatUsd(generation.cost_nanos)
                                    }}</span
                                >
                                <template v-else-if="generation.in_flight">
                                    When it's done
                                </template>
                                <template v-else>Not charged</template>
                            </dd>
                        </dl>
                        <p class="text-xs text-muted-foreground">
                            Includes our {{ generation.markup_percent }}% markup
                            on Pixelforge's cost. Failed generations are free.
                        </p>
                    </section>

                    <dl
                        class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1"
                    >
                        <template v-if="generation.completed_at">
                            <dt class="text-muted-foreground">Completed</dt>
                            <dd>
                                {{ formatDateTime(generation.completed_at) }}
                                <span class="text-muted-foreground">
                                    · took {{ tookSeconds }} s
                                </span>
                            </dd>
                        </template>
                        <template v-if="generation.image_expires_at">
                            <dt class="text-muted-foreground">Image link</dt>
                            <dd
                                :title="
                                    formatDateTime(generation.image_expires_at)
                                "
                            >
                                {{
                                    isExpired(generation)
                                        ? 'Expired'
                                        : 'Expires'
                                }}
                                {{ expiry }}
                            </dd>
                        </template>
                    </dl>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" @click="reuse(generation)">
                            <RotateCcw v-if="generation.status === 'failed'" />
                            <Repeat2 v-else />
                            {{
                                generation.status === 'failed'
                                    ? 'Try again'
                                    : 'Reuse settings'
                            }}
                        </Button>
                        <template v-if="imageAvailable && generation.image_url">
                            <Button
                                variant="outline"
                                @click="
                                    emit('useAsReference', generation.image_url)
                                "
                            >
                                <ImagePlus />
                                Use as reference
                            </Button>
                            <Button variant="outline" as-child>
                                <a :href="download.url(generation)" download>
                                    <Download />
                                    Download
                                </a>
                            </Button>
                            <Button variant="outline" as-child>
                                <a
                                    :href="generation.image_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <ExternalLink />
                                    Open original
                                </a>
                            </Button>
                        </template>
                    </div>

                    <nav
                        class="flex items-center justify-between gap-2 border-t pt-4"
                        aria-label="Generations on this page"
                    >
                        <IconButton
                            label="Newer generation (←)"
                            :disabled="!previous"
                            @click="show(previous)"
                        >
                            <ChevronLeft />
                        </IconButton>
                        <span class="text-muted-foreground tabular-nums">
                            {{ position + 1 }} of {{ generations.length }}
                        </span>
                        <IconButton
                            label="Older generation (→)"
                            :disabled="!next"
                            @click="show(next)"
                        >
                            <ChevronRight />
                        </IconButton>
                    </nav>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
