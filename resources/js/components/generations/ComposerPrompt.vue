<script setup lang="ts">
import { onKeyStroke } from '@vueuse/core';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { countCharacters } from '@/lib/pricing';

const props = defineProps<{
    maxLength: number;
    error?: string;
}>();

const prompt = defineModel<string>({ required: true });

const EXAMPLES = [
    {
        label: 'Lighthouse at dawn',
        prompt: 'A lighthouse on a cliff at dawn, oil painting',
    },
    {
        label: 'Fox in a café',
        prompt: 'A red fox reading a newspaper in a Paris café, watercolor',
    },
    {
        label: 'Pixel-art forge',
        prompt: "Isometric pixel art of a blacksmith's forge at night, glowing embers",
    },
    {
        label: 'Dew on a web',
        prompt: 'Macro photo of dew on a spider web at sunrise, shallow depth of field',
    },
];

const length = computed(() => countCharacters(prompt.value));
const remaining = computed(() => props.maxLength - length.value);
const nearLimit = computed(() => length.value > props.maxLength * 0.9);
const limitNote = computed(() => {
    if (remaining.value < 0) {
        return `· ${number(-remaining.value)} too many`;
    }

    return nearLimit.value ? `· ${number(remaining.value)} left` : '';
});

function focus(options?: FocusOptions) {
    document.getElementById('prompt')?.focus(options);
}

function number(value: number): string {
    return value.toLocaleString('en-US');
}

function pickExample(example: string) {
    prompt.value = example;
    focus();
}

function submitForm(event: KeyboardEvent) {
    (event.target as HTMLTextAreaElement).form?.requestSubmit();
}

// "/" jumps to the prompt from anywhere, unless you're typing somewhere already.
onKeyStroke('/', (event) => {
    const target = event.target instanceof HTMLElement ? event.target : null;

    if (
        event.metaKey ||
        event.ctrlKey ||
        event.altKey ||
        target?.isContentEditable ||
        target?.closest('input, textarea, select')
    ) {
        return;
    }

    event.preventDefault();
    focus();
});

defineExpose({ focus });
</script>

<template>
    <div class="grid gap-2">
        <Label for="prompt">Prompt</Label>
        <Textarea
            id="prompt"
            v-model="prompt"
            required
            placeholder="A copper teapot on a windowsill, morning light, film photo"
            class="max-h-64 min-h-28"
            aria-keyshortcuts="/"
            :aria-invalid="error ? true : undefined"
            :aria-describedby="
                error ? 'prompt-error prompt-length' : 'prompt-length'
            "
            @keydown.meta.enter.prevent="submitForm"
            @keydown.ctrl.enter.prevent="submitForm"
        />
        <InputError id="prompt-error" :message="error" />
        <div class="flex items-center gap-4 text-xs text-muted-foreground">
            <span class="hidden sm:inline">
                <kbd class="rounded border bg-muted px-1 py-0.5 font-sans"
                    >Ctrl</kbd
                >
                /
                <kbd class="rounded border bg-muted px-1 py-0.5 font-sans"
                    >⌘</kbd
                >
                +
                <kbd class="rounded border bg-muted px-1 py-0.5 font-sans"
                    >Enter</kbd
                >
                to generate
            </span>
            <span
                id="prompt-length"
                class="ml-auto font-mono tabular-nums"
                :class="{
                    'font-medium': nearLimit,
                    'text-foreground': nearLimit && remaining >= 0,
                    'text-destructive': remaining < 0,
                }"
            >
                {{ number(length) }} / {{ number(maxLength) }}
                <span class="sr-only">characters</span>
                {{ limitNote }}
            </span>
        </div>

        <div
            v-if="length === 0"
            class="flex flex-wrap items-center gap-2 text-sm"
        >
            <span class="text-muted-foreground">Need an idea?</span>
            <Button
                v-for="example in EXAMPLES"
                :key="example.label"
                type="button"
                variant="outline"
                class="rounded-full font-normal"
                :title="example.prompt"
                @click="pickExample(example.prompt)"
            >
                {{ example.label }}
            </Button>
        </div>
    </div>
</template>
