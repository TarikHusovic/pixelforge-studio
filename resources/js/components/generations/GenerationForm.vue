<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { AlertCircle, CircleCheck } from '@lucide/vue';
import { useLocalStorage, usePreferredReducedMotion } from '@vueuse/core';
import { computed, nextTick, useTemplateRef, watch } from 'vue';
import { store } from '@/actions/App/Http/Controllers/GenerationController';
import BuyCreditsDialog from '@/components/BuyCreditsDialog.vue';
import ComposerPrompt from '@/components/generations/ComposerPrompt.vue';
import CostBreakdown from '@/components/generations/CostBreakdown.vue';
import OptionCards from '@/components/generations/OptionCards.vue';
import QuoteSummary from '@/components/generations/QuoteSummary.vue';
import ReferenceImageList from '@/components/generations/ReferenceImageList.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatUsd, formatUsdExact } from '@/lib/money';
import { countCharacters, estimate } from '@/lib/pricing';
import type { Quote } from '@/lib/pricing';
import type { Generation, Pricing } from '@/types';

// Pixelforge's limits; the server validates the same.
const MAX_PROMPT_LENGTH = 4000;
const MAX_REFERENCE_IMAGES = 4;

const DEFAULTS = {
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
};

const QUALITY_DETAILS: Record<string, string> = {
    low: 'Quick draft',
    medium: 'Balanced',
    high: 'Finest detail',
};

const props = defineProps<{
    pricing: Pricing | null;
    /** Something of the user's is being generated. */
    busy?: boolean;
}>();

const page = usePage();
// From the server's own balance check, for the rare case it disagrees with ours. It stays
// until the next real visit (polls keep errors), such as buying credits or generating.
const creditsError = computed(() => page.props.errors.credits);

const formElement = useTemplateRef('formElement');
const reducedMotion = usePreferredReducedMotion();
const promptField = useTemplateRef('promptField');
const credits = computed(() => page.props.credits);
const available = computed(() => credits.value?.available_nanos ?? 0);

// Per user, so a shared browser doesn't hand one person's prompt to the next.
const draft = useLocalStorage(
    `pixelforge.composer-draft.${page.props.auth.user.id}`,
    DEFAULTS,
    { mergeDefaults: true },
);

const form = useForm({
    ...draft.value,
    reference_images: [...draft.value.reference_images],
});

watch(
    () => form.data(),
    (data) => (draft.value = data),
    { deep: true },
);

// A restored draft or a reused generation can name an option Pixelforge no longer
// offers, and estimate() needs real ones.
watch(() => props.pricing, fitToPricing, { immediate: true });

const promptLength = computed(() => countCharacters(form.prompt));
const referenceImages = computed(() =>
    form.reference_images.filter((url) => url.trim() !== ''),
);

const quote = computed(() =>
    props.pricing ? quoteAt(props.pricing, form.size, form.quality) : null,
);

const qualityOptions = computed(() => {
    const pricing = props.pricing;

    if (!pricing) {
        return [];
    }

    return Object.keys(pricing.tokens.output).map((quality) => ({
        value: quality,
        label: quality[0].toUpperCase() + quality.slice(1),
        detail: QUALITY_DETAILS[quality] ?? '',
        price: quoteAt(pricing, form.size, quality).estimated,
    }));
});

const sizeOptions = computed(() => {
    const pricing = props.pricing;

    if (!pricing) {
        return [];
    }

    return Object.keys(pricing.tokens.output[form.quality]).map((size) => {
        const [width, height] = size.split('x').map(Number);

        return {
            value: size,
            label: shape(width, height),
            detail: size.replace('x', '×'),
            aspectRatio: `${width} / ${height}`,
            price: quoteAt(pricing, size, form.quality).estimated,
        };
    });
});

// The server reserves the max, so that's what has to fit.
const shortOfCredits = computed(
    () => quote.value !== null && available.value < quote.value.max,
);

const affordable = computed(() =>
    quote.value ? Math.floor(available.value / quote.value.estimated) : 0,
);

const blockedReason = computed(() => {
    if (!quote.value) {
        return "Generating is paused: we can't quote a price right now.";
    }

    if (promptLength.value === 0) {
        return 'Write a prompt to generate.';
    }

    if (promptLength.value > MAX_PROMPT_LENGTH) {
        return `Shorten the prompt to ${MAX_PROMPT_LENGTH.toLocaleString('en-US')} characters.`;
    }

    return shortOfCredits.value ? 'Not enough credits for this one.' : null;
});

const canGenerate = computed(
    () => blockedReason.value === null && !form.processing,
);

/** What the current prompt and references would cost at this size and quality. */
function quoteAt(pricing: Pricing, size: string, quality: string): Quote {
    return estimate(
        pricing,
        form.prompt,
        size,
        quality,
        referenceImages.value.length,
    );
}

function shape(width: number, height: number): string {
    if (width === height) {
        return 'Square';
    }

    return width > height ? 'Landscape' : 'Portrait';
}

function fitToPricing() {
    if (!props.pricing) {
        return;
    }

    const { output } = props.pricing.tokens;

    form.quality = offered(form.quality, Object.keys(output), DEFAULTS.quality);
    form.size = offered(
        form.size,
        Object.keys(output[form.quality]),
        DEFAULTS.size,
    );
}

function offered(value: string, options: string[], preferred: string): string {
    if (options.includes(value)) {
        return value;
    }

    return options.includes(preferred) ? preferred : options[0];
}

function submit() {
    if (!canGenerate.value) {
        return;
    }

    // Drop empty rows here rather than in transform(), so error keys like
    // reference_images.1 still point at the row the user sees.
    form.reference_images = referenceImages.value;
    form.submit(store(), { preserveScroll: true });
}

/** "Try again": put a past generation's settings back in the composer. */
function prefill(generation: Generation) {
    form.prompt = generation.prompt;
    form.size = generation.size;
    form.quality = generation.quality;
    form.reference_images = [...generation.reference_images];
    fitToPricing();
    form.clearErrors();
    formElement.value?.scrollIntoView({
        behavior: reducedMotion.value === 'reduce' ? 'auto' : 'smooth',
        block: 'start',
    });
    focusPrompt();
}

/** "Use as reference". */
function addReference(url: string): 'added' | 'already-added' | 'full' {
    const urls = referenceImages.value;

    if (urls.includes(url)) {
        return 'already-added';
    }

    if (urls.length >= MAX_REFERENCE_IMAGES) {
        return 'full';
    }

    form.reference_images = [...urls, url];

    return 'added';
}

function focusPrompt() {
    promptField.value?.focus({ preventScroll: true });
}

defineExpose({ prefill, addReference });
</script>

<template>
    <form
        ref="formElement"
        aria-labelledby="composer-heading"
        class="relative isolate grid scroll-mt-20 gap-6 rounded-xl border bg-card p-4 sm:p-5"
        @submit.prevent="submit"
    >
        <div
            v-if="busy"
            aria-hidden="true"
            class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-40 animate-breathe rounded-t-xl bg-radial-[at_50%_0%] from-ember/20 to-transparent to-70%"
        />
        <header class="grid gap-1">
            <h2 id="composer-heading" class="font-display text-2xl">Create</h2>
            <p class="text-sm text-muted-foreground">
                Describe an image. You'll see the price before you generate.
            </p>
        </header>

        <Alert v-if="!pricing" variant="destructive">
            <AlertCircle />
            <AlertTitle>We can't quote a price right now</AlertTitle>
            <AlertDescription>
                Pixelforge's pricing couldn't be loaded, and we won't start a
                generation without telling you what it costs, so generating is
                paused. Your generations still work. Reload the page in a minute
                to try again.
            </AlertDescription>
        </Alert>

        <ComposerPrompt
            ref="promptField"
            v-model="form.prompt"
            :max-length="MAX_PROMPT_LENGTH"
            :error="form.errors.prompt"
        />

        <template v-if="pricing">
            <div class="grid gap-2">
                <OptionCards
                    v-model="form.quality"
                    legend="Quality"
                    name="quality"
                    :options="qualityOptions"
                />
                <InputError :message="form.errors.quality" />
            </div>
            <div class="grid gap-2">
                <OptionCards
                    v-model="form.size"
                    legend="Size"
                    name="size"
                    :options="sizeOptions"
                />
                <InputError :message="form.errors.size" />
            </div>
        </template>

        <ReferenceImageList
            v-model="form.reference_images"
            :max="MAX_REFERENCE_IMAGES"
            :pricing="pricing"
            :errors="form.errors"
        />

        <!-- Sticks to the bottom of whatever scrolls the composer, so the price stays in view. -->
        <footer
            class="-mx-4 -mb-4 grid gap-3 rounded-b-xl border-t bg-card px-4 pt-4 pb-4 sm:-mx-5 sm:-mb-5 sm:px-5 sm:pb-5 lg:sticky lg:bottom-0"
        >
            <QuoteSummary v-if="pricing && quote" :quote="quote">
                <CostBreakdown
                    :pricing="pricing"
                    :prompt="form.prompt"
                    :size="form.size"
                    :quality="form.quality"
                    :reference-images="referenceImages.length"
                />
            </QuoteSummary>

            <div v-if="credits" class="grid gap-0.5 text-sm">
                <p v-if="!shortOfCredits">
                    <span
                        class="font-mono tabular-nums"
                        :title="formatUsdExact(available)"
                        >{{ formatUsd(available) }}</span
                    >
                    available<span v-if="quote" class="text-muted-foreground">
                        · enough for about
                        {{ affordable.toLocaleString('en-US') }} like this</span
                    >
                </p>
                <p
                    v-if="credits.reserved_nanos > 0"
                    class="text-xs text-muted-foreground"
                >
                    <span
                        class="font-mono tabular-nums"
                        :title="formatUsdExact(credits.reserved_nanos)"
                        >{{ formatUsd(credits.reserved_nanos) }}</span
                    >
                    held for generations in progress
                </p>
            </div>

            <!-- Not a live region: it would re-read on every keystroke; #generate-status says it once. -->
            <Alert
                v-if="shortOfCredits && quote"
                variant="destructive"
                role="none"
            >
                <AlertCircle />
                <AlertTitle>Not enough credits</AlertTitle>
                <AlertDescription class="grid justify-items-start gap-2">
                    <p>
                        This can cost up to
                        <span class="font-mono tabular-nums">{{
                            formatUsd(quote.max)
                        }}</span
                        >; you have
                        <span class="font-mono tabular-nums">{{
                            formatUsd(available)
                        }}</span>
                        available.
                    </p>
                    <!-- Buying hides this alert and the dialog with it, so hand focus back to the prompt. -->
                    <BuyCreditsDialog
                        :per-image-nanos="quote.estimated"
                        @purchased="nextTick(focusPrompt)"
                    >
                        <Button type="button" variant="outline">
                            Buy credits
                        </Button>
                    </BuyCreditsDialog>
                </AlertDescription>
            </Alert>
            <InputError v-else :message="creditsError" />

            <Button
                type="submit"
                size="lg"
                class="w-full hover:shadow-ember"
                :disabled="!canGenerate"
                aria-describedby="generate-status"
            >
                <Spinner v-if="form.processing" />
                Generate
                <span v-if="quote" class="font-mono tabular-nums">
                    · {{ formatUsd(quote.estimated) }}
                </span>
            </Button>
            <p
                id="generate-status"
                aria-live="polite"
                class="flex min-h-4 items-center justify-center gap-1.5 text-center text-xs text-muted-foreground"
            >
                <template v-if="form.recentlySuccessful">
                    <CircleCheck class="size-3.5 text-success" />
                    Started. It's at the top of your gallery.
                </template>
                <template v-else-if="blockedReason">
                    {{ blockedReason }}
                </template>
            </p>
        </footer>
    </form>
</template>
