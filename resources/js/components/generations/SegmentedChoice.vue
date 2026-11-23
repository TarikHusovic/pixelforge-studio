<script setup lang="ts">
defineProps<{
    legend: string;
    name: string;
    options: { value: string; label: string }[];
}>();

const model = defineModel<string>({ required: true });
</script>

<template>
    <fieldset>
        <legend class="mb-2 text-sm font-medium">{{ legend }}</legend>
        <div
            class="inline-flex flex-wrap gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800"
        >
            <label
                v-for="option in options"
                :key="option.value"
                class="cursor-pointer rounded-md px-3 py-1.5 text-sm text-neutral-500 transition-colors hover:bg-neutral-200/60 hover:text-black has-checked:bg-white has-checked:text-foreground has-checked:shadow-xs has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50 dark:text-neutral-400 dark:hover:bg-neutral-700/60 dark:has-checked:bg-neutral-700 dark:has-checked:text-neutral-100"
            >
                <input
                    v-model="model"
                    type="radio"
                    class="sr-only"
                    :name="name"
                    :value="option.value"
                />
                {{ option.label }}
            </label>
        </div>
    </fieldset>
</template>
