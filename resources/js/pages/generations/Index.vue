<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { ArrowLeft, ArrowUp, ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, ref, useTemplateRef, watch } from 'vue';
import { toast } from 'vue-sonner';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { failureReason } from '@/components/generations/display';
import GalleryToolbar from '@/components/generations/GalleryToolbar.vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import GenerationDetailsDialog from '@/components/generations/GenerationDetailsDialog.vue';
import GenerationForm from '@/components/generations/GenerationForm.vue';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/generations';
import type {
    Generation,
    GenerationCounts,
    GenerationFilter,
    GenerationFilters,
    Paginated,
    Pricing,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Studio', href: index() }],
    },
});

const props = defineProps<{
    generations: Paginated<Generation>;
    pricing: Pricing | null;
    filters: GenerationFilters;
    counts: GenerationCounts;
}>();

const FILTER_WORDS: Record<GenerationFilter, string> = {
    all: '',
    in_progress: 'in-progress ',
    succeeded: 'succeeded ',
    failed: 'failed ',
};

const generationForm = useTemplateRef('generationForm');
/** The generation open in the details dialog. */
const selectedId = ref<string | null>(null);

// Live status: poll while any of the user's generations is on its way, not just this page's.
const inProgress = computed(() => props.counts.in_progress);
const { start, stop } = usePoll(
    2000,
    { only: ['generations', 'credits', 'counts'] },
    { autoStart: false, mode: 'rest' },
);
watch(inProgress, (count) => (count > 0 ? start() : stop()), {
    immediate: true,
});

const title = computed(() =>
    inProgress.value > 0 ? `(${inProgress.value}) In progress` : 'Studio',
);

const firstRun = computed(() => props.counts.all === 0);
const noMatches = computed(() => {
    const { status, search } = props.filters;

    return `No ${FILTER_WORDS[status]}generations${search ? ` match “${search}”` : ''}.`;
});

const page = computed(() => props.generations.meta.current_page);
const lastPage = computed(() => props.generations.meta.last_page);

// Say so when generations finish: in detail for the ones on screen, as a count for the rest
// (another page, or a tab they've just left, like "In progress").
watch(
    () => [props.generations.data, props.counts] as const,
    ([current, counts], [previous, before]) => {
        const wasInFlight = new Set(
            previous.filter(({ in_flight }) => in_flight).map(({ id }) => id),
        );
        const finishedHere = current.filter(
            ({ id, in_flight }) => wasInFlight.has(id) && !in_flight,
        );
        const finishedElsewhere =
            counts.succeeded +
            counts.failed -
            (before.succeeded + before.failed) -
            finishedHere.length;

        finishedHere.forEach(announce);

        if (finishedElsewhere > 0) {
            toast(
                finishedElsewhere === 1
                    ? 'A generation finished'
                    : `${finishedElsewhere} generations finished`,
                {
                    action: {
                        label: 'Show all',
                        onClick: () => router.visit(index()),
                    },
                },
            );
        }
    },
);

function announce(generation: Generation) {
    if (generation.status === 'succeeded') {
        toast.success('Image ready', {
            description: shorten(generation.prompt),
            action: {
                label: 'View',
                onClick: () => (selectedId.value = generation.id),
            },
        });
    } else {
        toast.error('Generation failed. Not charged.', {
            description: failureReason(generation),
        });
    }
}

function shorten(prompt: string): string {
    return prompt.length > 60 ? `${prompt.slice(0, 59)}…` : prompt;
}

function reuse(generation: Generation) {
    selectedId.value = null;
    generationForm.value?.prefill(generation);
}

function useAsReference(url: string) {
    const result = generationForm.value?.addReference(url);

    if (result === 'added') {
        toast.success('Added as a reference image');
    } else if (result === 'already-added') {
        toast.info("It's already one of the reference images.");
    } else {
        toast.error('All 4 reference images are set. Remove one first.');
    }
}

/** After Newer / Older: the new page starts at the top of the gallery, not where the buttons were. */
function showGalleryTop() {
    document
        .getElementById('gallery-heading')
        ?.scrollIntoView({ block: 'start' });
}
</script>

<template>
    <Head :title="title" />

    <div
        class="mx-auto grid w-full max-w-[1800px] gap-8 p-4 lg:grid-cols-[minmax(340px,400px)_minmax(0,1fr)] lg:items-start"
    >
        <h1 class="sr-only">Studio</h1>

        <!-- Below the sticky header (h-14 + p-4), and no taller than the rest of the screen. -->
        <div
            class="lg:sticky lg:top-18 lg:max-h-[calc(100svh-5.5rem)] lg:[scrollbar-width:thin] lg:overflow-y-auto"
        >
            <GenerationForm
                ref="generationForm"
                :pricing="pricing"
                :busy="inProgress > 0"
            />
        </div>

        <section
            class="grid min-w-0 content-start gap-4"
            aria-labelledby="gallery-heading"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3"
            >
                <h2 id="gallery-heading" class="font-display text-2xl">
                    Gallery
                </h2>
                <GalleryToolbar
                    v-if="!firstRun"
                    :filters="filters"
                    :counts="counts"
                />
            </div>

            <div
                v-if="firstRun"
                class="grid place-items-center gap-5 rounded-xl border border-dashed bg-grain px-6 py-16 text-center"
            >
                <div
                    class="grid size-24 place-items-center rounded-xl border bg-card pixel-grid"
                >
                    <AppLogoIcon class="size-12 text-ash" />
                </div>
                <div class="grid max-w-sm gap-2">
                    <p class="font-display text-lg">Nothing forged yet</p>
                    <p class="text-sm text-muted-foreground">
                        <ArrowLeft
                            class="me-1 hidden size-4 align-text-bottom lg:inline"
                            aria-hidden="true"
                        />
                        <ArrowUp
                            class="me-1 inline size-4 align-text-bottom lg:hidden"
                            aria-hidden="true"
                        />
                        Describe an image and press Generate to forge your first
                        one. You'll see the price before you generate, and
                        failed generations are free.
                    </p>
                </div>
            </div>

            <div
                v-else-if="generations.data.length === 0"
                class="grid place-items-center gap-3 rounded-xl border border-dashed px-6 py-12 text-center text-sm"
            >
                <p class="text-muted-foreground">{{ noMatches }}</p>
                <Button variant="outline" as-child>
                    <Link
                        :href="index()"
                        :only="['generations', 'filters', 'counts']"
                        preserve-state
                        preserve-scroll
                        replace
                    >
                        Clear filters
                    </Link>
                </Button>
            </div>

            <div
                v-else
                class="grid grid-cols-[repeat(auto-fill,minmax(220px,1fr))] items-start gap-4"
            >
                <GenerationCard
                    v-for="(generation, position) in generations.data"
                    :key="generation.id"
                    :generation="generation"
                    class="animate-rise"
                    :style="{
                        animationDelay: `${Math.min(position, 8) * 40}ms`,
                    }"
                    @reuse="reuse"
                    @open="selectedId = $event.id"
                />
            </div>

            <nav
                v-if="lastPage > 1"
                class="flex items-center justify-between gap-4 border-t pt-4"
                aria-label="Pagination"
            >
                <Button
                    v-if="generations.links.prev"
                    variant="outline"
                    as-child
                >
                    <Link
                        :href="generations.links.prev"
                        :only="['generations']"
                        preserve-state
                        preserve-scroll
                        :on-success="showGalleryTop"
                    >
                        <ChevronLeft />
                        Newer
                    </Link>
                </Button>
                <span v-else />
                <span class="text-sm text-muted-foreground tabular-nums">
                    Page {{ page }} of {{ lastPage }}
                </span>
                <Button
                    v-if="generations.links.next"
                    variant="outline"
                    as-child
                >
                    <Link
                        :href="generations.links.next"
                        :only="['generations']"
                        preserve-state
                        preserve-scroll
                        :on-success="showGalleryTop"
                    >
                        Older
                        <ChevronRight />
                    </Link>
                </Button>
                <span v-else />
            </nav>
        </section>
    </div>

    <GenerationDetailsDialog
        v-model="selectedId"
        :generations="generations.data"
        @reuse="reuse"
        @use-as-reference="useAsReference"
    />
</template>
