<script setup lang="ts">
import { ImagePlus, X } from '@lucide/vue';
import { computed, nextTick } from 'vue';
import IconButton from '@/components/generations/IconButton.vue';
import ReferenceImageThumb from '@/components/generations/ReferenceImageThumb.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatUsd, formatUsdExact } from '@/lib/money';
import { price } from '@/lib/pricing';
import type { Pricing } from '@/types';

const props = defineProps<{
    max: number;
    pricing: Pricing | null;
    /** The form's errors: reference_images.{i} for a row, reference_images for the list. */
    errors: Partial<Record<string, string>>;
}>();

const urls = defineModel<string[]>({ required: true });

// In script, not the template: vue-tsc 3.3 chokes on "//" in a template attribute.
const EXAMPLE_URL = 'https://example.com/style.png';

const perImage = computed(() => {
    if (!props.pricing) {
        return null;
    }

    const tokens = props.pricing.tokens.per_reference_image;

    return {
        tokens,
        price: price(props.pricing, { input_image_tokens: tokens }),
    };
});

async function add() {
    urls.value = [...urls.value, ''];
    await nextTick();
    document
        .getElementById(`reference-image-${urls.value.length - 1}`)
        ?.focus();
}

function update(index: number, url: string | number) {
    urls.value = urls.value.with(index, String(url));
}

async function remove(index: number) {
    urls.value = urls.value.toSpliced(index, 1);
    // The focused button went with its row: move to the row that took its place, or Add.
    await nextTick();
    const next = Math.min(index, urls.value.length - 1);
    (
        document.getElementById(`reference-image-${next}`) ??
        document.getElementById('add-reference-image')
    )?.focus();
}
</script>

<template>
    <fieldset class="grid gap-2">
        <legend class="mb-1 text-sm font-medium">
            Reference images
            <span class="font-normal text-muted-foreground">(optional)</span>
        </legend>
        <p class="text-xs text-muted-foreground">
            Up to {{ max }} image URLs to guide the result.
            <template v-if="perImage">
                Each adds {{ perImage.tokens }} input tokens (≈
                <span
                    class="font-mono tabular-nums"
                    :title="formatUsdExact(perImage.price)"
                    >{{ formatUsd(perImage.price) }}</span
                >).
            </template>
        </p>

        <div v-for="(url, i) in urls" :key="i" class="grid gap-1">
            <div class="flex items-center gap-2">
                <ReferenceImageThumb :url="url" />
                <Input
                    :id="`reference-image-${i}`"
                    :model-value="url"
                    type="url"
                    :placeholder="EXAMPLE_URL"
                    :aria-label="`Reference image ${i + 1} URL`"
                    :aria-invalid="
                        errors[`reference_images.${i}`] ? true : undefined
                    "
                    @update:model-value="update(i, $event)"
                />
                <IconButton
                    type="button"
                    :label="`Remove reference image ${i + 1}`"
                    @click="remove(i)"
                >
                    <X />
                </IconButton>
            </div>
            <InputError :message="errors[`reference_images.${i}`]" />
        </div>
        <InputError :message="errors.reference_images" />

        <Button
            v-if="urls.length < max"
            id="add-reference-image"
            type="button"
            variant="ghost"
            class="w-fit text-muted-foreground"
            @click="add"
        >
            <ImagePlus />
            Add reference image
        </Button>
    </fieldset>
</template>
