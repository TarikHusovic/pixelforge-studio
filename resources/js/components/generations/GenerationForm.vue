<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { AlertCircle, Plus, X } from '@lucide/vue';
import { computed, useTemplateRef } from 'vue';
import { store } from '@/actions/App/Http/Controllers/GenerationController';
import BuyCreditsDialog from '@/components/BuyCreditsDialog.vue';
import SegmentedChoice from '@/components/generations/SegmentedChoice.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatUsd, formatUsdExact } from '@/lib/money';
import { countCharacters, estimate } from '@/lib/pricing';
import type { Generation, Pricing } from '@/types';

// Pixelforge's limits; the server validates the same.
const MAX_PROMPT_LENGTH = 4000;
const MAX_REFERENCE_IMAGES = 4;
// In script, not the template: vue-tsc 3.3 chokes on "//" in a template attribute.
const REFERENCE_IMAGE_EXAMPLE = 'https://example.com/style.png';

const props = defineProps<{
    pricing: Pricing | null;
}>();

const page = usePage();
// From the server's own balance check. A page error, not a field one: page errors
// reset on every reload (polls too), so it can't linger once credits change.
const creditsError = computed(() => page.props.errors.credits);

const formElement = useTemplateRef('formElement');
const credits = computed(() => page.props.credits);
const available = computed(() => credits.value?.available_nanos ?? 0);

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

const qualityOptions = computed(() =>
    Object.keys(props.pricing?.tokens.output ?? {}).map((quality) => ({
        value: quality,
        label: quality[0].toUpperCase() + quality.slice(1),
    })),
);

const sizeOptions = computed(() =>
    Object.keys(props.pricing?.tokens.output[form.quality] ?? {}).map(
        (size) => ({ value: size, label: sizeLabel(size) }),
    ),
);

const promptLength = computed(() => countCharacters(form.prompt));
const referenceImages = computed(() =>
    form.reference_images.filter((url) => url.trim() !== ''),
);

const quote = computed(() =>
    props.pricing
        ? estimate(
              props.pricing,
              form.prompt,
              form.size,
              form.quality,
              referenceImages.value.length,
          )
        : null,
);

// The server reserves the max, so that's what has to fit.
const shortOfCredits = computed(
    () => quote.value !== null && available.value < quote.value.max,
);

const canGenerate = computed(
    () =>
        quote.value !== null &&
        !shortOfCredits.value &&
        promptLength.value <= MAX_PROMPT_LENGTH &&
        !form.processing,
);

function sizeLabel(size: string): string {
    const [width, height] = size.split('x').map(Number);
    const shape =
        width === height ? 'Square' : width > height ? 'Landscape' : 'Portrait';

    return `${shape} ${size.replace('x', '×')}`;
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
    form.clearErrors();
    formElement.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    document.getElementById('prompt')?.focus({ preventScroll: true });
}

defineExpose({ prefill });
</script>

<template>
    <form
        ref="formElement"
        class="grid scroll-mt-20 gap-6 rounded-xl border p-4 sm:p-6"
        @submit.prevent="submit"
    >
        <Alert v-if="!pricing" variant="destructive">
            <AlertCircle />
            <AlertTitle>We can't quote a price right now</AlertTitle>
            <AlertDescription>
                Pixelforge's pricing couldn't be loaded, and we won't start a
                generation without telling you what it costs, so generating is
                paused. Your generations below still work. Reload the page in a
                minute to try again.
            </AlertDescription>
        </Alert>

        <div class="grid gap-2">
            <Label for="prompt">Prompt</Label>
            <Textarea
                id="prompt"
                v-model="form.prompt"
                required
                rows="4"
                placeholder="A lighthouse on a cliff at dawn, oil painting"
                class="min-h-24"
                :aria-invalid="form.errors.prompt ? true : undefined"
                :aria-describedby="
                    form.errors.prompt
                        ? 'prompt-error prompt-length'
                        : 'prompt-length'
                "
                @keydown.meta.enter.prevent="formElement?.requestSubmit()"
                @keydown.ctrl.enter.prevent="formElement?.requestSubmit()"
            />
            <div class="flex gap-4">
                <InputError id="prompt-error" :message="form.errors.prompt" />
                <span
                    id="prompt-length"
                    class="ml-auto text-xs text-muted-foreground tabular-nums"
                    :class="{
                        'text-red-600 dark:text-red-500':
                            promptLength > MAX_PROMPT_LENGTH,
                    }"
                >
                    {{ promptLength }} / {{ MAX_PROMPT_LENGTH }} characters
                </span>
            </div>
        </div>

        <div v-if="pricing" class="flex flex-wrap gap-6">
            <div class="grid gap-2">
                <SegmentedChoice
                    v-model="form.quality"
                    legend="Quality"
                    name="quality"
                    :options="qualityOptions"
                />
                <InputError :message="form.errors.quality" />
            </div>
            <div class="grid gap-2">
                <SegmentedChoice
                    v-model="form.size"
                    legend="Size"
                    name="size"
                    :options="sizeOptions"
                />
                <InputError :message="form.errors.size" />
            </div>
        </div>

        <fieldset class="grid gap-2">
            <legend class="text-sm font-medium">
                Reference images
                <span class="font-normal text-muted-foreground">
                    (optional)
                </span>
            </legend>
            <p class="text-xs text-muted-foreground">
                Up to {{ MAX_REFERENCE_IMAGES }} image URLs to guide the result.
                <span v-if="pricing">
                    Each one adds {{ pricing.tokens.per_reference_image }} input
                    tokens to the price.
                </span>
            </p>
            <div
                v-for="(_, i) in form.reference_images"
                :key="i"
                class="grid gap-1"
            >
                <div class="flex gap-2">
                    <Input
                        v-model="form.reference_images[i]"
                        type="url"
                        :placeholder="REFERENCE_IMAGE_EXAMPLE"
                        :aria-label="`Reference image ${i + 1} URL`"
                        :aria-invalid="
                            form.errors[`reference_images.${i}`]
                                ? true
                                : undefined
                        "
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Remove reference image ${i + 1}`"
                        @click="form.reference_images.splice(i, 1)"
                    >
                        <X />
                    </Button>
                </div>
                <InputError :message="form.errors[`reference_images.${i}`]" />
            </div>
            <InputError :message="form.errors.reference_images" />
            <Button
                v-if="form.reference_images.length < MAX_REFERENCE_IMAGES"
                type="button"
                variant="outline"
                size="sm"
                class="w-fit"
                @click="form.reference_images.push('')"
            >
                <Plus />
                Add reference image
            </Button>
        </fieldset>

        <div
            class="flex flex-wrap items-end justify-between gap-4 border-t pt-4"
        >
            <div v-if="pricing && quote" class="grid max-w-prose gap-1">
                <p class="flex flex-wrap items-baseline gap-x-2">
                    <span
                        class="text-2xl font-semibold tabular-nums"
                        :title="formatUsdExact(quote.estimated)"
                    >
                        {{ formatUsd(quote.estimated) }}
                    </span>
                    <span
                        class="text-sm text-muted-foreground"
                        :title="formatUsdExact(quote.max)"
                    >
                        estimated, up to {{ formatUsd(quote.max) }}
                    </span>
                </p>
                <p class="text-xs text-muted-foreground">
                    The final price is known when the image is done, since text
                    and reasoning token counts vary. You pay Pixelforge's cost +
                    {{ pricing.markup_percent }}%. Failed generations are free.
                </p>
                <p class="text-sm">
                    You have
                    <span
                        class="font-medium tabular-nums"
                        :title="formatUsdExact(available)"
                        >{{ formatUsd(available) }}</span
                    >
                    available<template
                        v-if="credits && credits.reserved_nanos > 0"
                    >
                        ({{ formatUsd(credits.reserved_nanos) }} is held for
                        generations in progress)</template
                    >.
                </p>
            </div>

            <div class="ml-auto flex items-center gap-3">
                <span
                    v-if="form.recentlySuccessful"
                    class="text-sm text-muted-foreground"
                >
                    Started, see it below.
                </span>
                <span
                    v-else
                    class="hidden text-xs text-muted-foreground sm:inline"
                >
                    Ctrl/⌘ + Enter
                </span>
                <Button type="submit" :disabled="!canGenerate">
                    <Spinner v-if="form.processing" />
                    Generate
                </Button>
            </div>
        </div>

        <Alert v-if="shortOfCredits && quote" variant="destructive">
            <AlertCircle />
            <AlertTitle>Not enough credits</AlertTitle>
            <AlertDescription class="grid justify-items-start gap-2">
                <p>
                    This can cost up to {{ formatUsd(quote.max) }} and you have
                    {{ formatUsd(available) }} available.
                </p>
                <BuyCreditsDialog>
                    <Button type="button" variant="outline" size="sm">
                        Buy credits
                    </Button>
                </BuyCreditsDialog>
            </AlertDescription>
        </Alert>
        <InputError v-else :message="creditsError" />
    </form>
</template>
