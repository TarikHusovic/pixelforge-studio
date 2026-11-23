<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, useTemplateRef, watch } from 'vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import GenerationForm from '@/components/generations/GenerationForm.vue';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/generations';
import type { Generation, Paginated, Pricing } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: index() }],
    },
});

const props = defineProps<{
    generations: Paginated<Generation>;
    pricing: Pricing | null;
}>();

const composer = useTemplateRef('composer');

// Live status: poll while anything on this page is still on its way.
const inFlight = computed(() =>
    props.generations.data.some((generation) =>
        ['pending', 'queued', 'processing'].includes(generation.status),
    ),
);
const { start, stop } = usePoll(
    2000,
    { only: ['generations', 'credits'] },
    { autoStart: false, mode: 'rest' },
);
watch(inFlight, (busy) => (busy ? start() : stop()), { immediate: true });

const page = computed(() => props.generations.meta.current_page);
const lastPage = computed(() => props.generations.meta.last_page);
</script>

<template>
    <Head title="Generate" />

    <div class="mx-auto grid w-full max-w-7xl gap-8 p-4">
        <GenerationForm ref="composer" :pricing="pricing" />

        <section class="grid gap-4" aria-labelledby="history-heading">
            <h2 id="history-heading" class="text-lg font-semibold">
                Your generations
            </h2>

            <p
                v-if="generations.meta.total === 0"
                class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                No generations yet. Write a prompt above to create your first
                image.
            </p>

            <div
                v-else
                class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
            >
                <GenerationCard
                    v-for="generation in generations.data"
                    :key="generation.id"
                    :generation="generation"
                    @retry="composer?.load($event)"
                />
            </div>

            <nav
                v-if="lastPage > 1"
                class="flex items-center justify-between gap-4"
                aria-label="Pagination"
            >
                <Button v-if="page > 1" variant="outline" size="sm" as-child>
                    <Link
                        :href="index({ query: { page: page - 1 } })"
                        preserve-scroll
                    >
                        <ChevronLeft />
                        Newer
                    </Link>
                </Button>
                <span v-else />
                <span class="text-sm text-muted-foreground">
                    Page {{ page }} of {{ lastPage }}
                </span>
                <Button
                    v-if="page < lastPage"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="index({ query: { page: page + 1 } })"
                        preserve-scroll
                    >
                        Older
                        <ChevronRight />
                    </Link>
                </Button>
                <span v-else />
            </nav>
        </section>
    </div>
</template>
