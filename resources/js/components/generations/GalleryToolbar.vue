<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Search, X } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { ref, watch } from 'vue';
import IconButton from '@/components/generations/IconButton.vue';
import { Input } from '@/components/ui/input';
import { index } from '@/routes/generations';
import type {
    GenerationCounts,
    GenerationFilter,
    GenerationFilters,
} from '@/types';

const props = defineProps<{
    filters: GenerationFilters;
    counts: GenerationCounts;
}>();

const TABS: { value: GenerationFilter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'succeeded', label: 'Succeeded' },
    { value: 'failed', label: 'Failed' },
];

// The server caps searches at the same length.
const MAX_SEARCH_LENGTH = 100;

// Swaps the gallery in place, so the composer keeps whatever is typed in it.
const VISIT = {
    only: ['generations', 'filters', 'counts'],
    preserveState: true,
    preserveScroll: true,
    replace: true,
};

const search = ref(props.filters.search);

function filterUrl(status: GenerationFilter, term: string): string {
    return index.url({
        query: {
            status: status === 'all' ? undefined : status,
            search: term.trim() || undefined,
        },
    });
}

/** The term this box last asked for, to tell the server's answer from a change made elsewhere. */
let requested = props.filters.search;

watchDebounced(
    search,
    (term) => {
        requested = term.trim();

        if (requested !== props.filters.search) {
            router.get(filterUrl(props.filters.status, requested), {}, VISIT);
        }
    },
    { debounce: 300 },
);

function clearSearch() {
    search.value = '';
    // The clear button goes away with the text, so keep the user in the search field.
    document.getElementById('gallery-search')?.focus();
}

// Follow changes made elsewhere ("Clear filters", or a new generation resetting them), but not
// the answer to an earlier keystroke, which would wipe out what the user typed since.
watch(
    () => props.filters.search,
    (term) => {
        if (term !== requested) {
            search.value = requested = term;
        }
    },
);
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <nav
            class="flex max-w-full [scrollbar-width:none] gap-0.5 overflow-x-auto rounded-lg bg-muted p-1 sm:gap-1"
            aria-label="Filter by status"
        >
            <Link
                v-for="tab in TABS"
                :key="tab.value"
                :href="filterUrl(tab.value, search)"
                v-bind="VISIT"
                class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-md px-2 text-sm whitespace-nowrap text-muted-foreground transition-colors outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-[current=page]:bg-card aria-[current=page]:text-foreground aria-[current=page]:shadow-xs sm:px-3"
                :aria-current="
                    tab.value === filters.status ? 'page' : undefined
                "
            >
                {{ tab.label }}
                <span
                    class="text-xs tabular-nums"
                    :class="{
                        'font-medium text-ember':
                            tab.value === 'in_progress' &&
                            counts.in_progress > 0,
                    }"
                    >{{ counts[tab.value] }}</span
                >
            </Link>
        </nav>

        <div class="relative min-w-48 flex-1">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                id="gallery-search"
                v-model="search"
                type="search"
                aria-label="Search prompts"
                placeholder="Search prompts"
                :maxlength="MAX_SEARCH_LENGTH"
                class="ps-9 pe-10 [&::-webkit-search-cancel-button]:appearance-none"
            />
            <IconButton
                v-if="search"
                label="Clear search"
                class="absolute top-1/2 right-0.5 -translate-y-1/2"
                @click="clearSearch"
            >
                <X />
            </IconButton>
        </div>
    </div>
</template>
