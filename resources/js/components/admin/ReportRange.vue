<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { report } from '@/routes/admin';

const props = defineProps<{
    /** Inclusive UTC days the report covers. */
    range: { from: string; to: string };
}>();

/** A UTC day as YYYY-MM-DD. Date.UTC rolls over, so day 0 is the last day of the month before. */
function utcDay(year: number, month: number, day: number): string {
    return new Date(Date.UTC(year, month, day)).toISOString().slice(0, 10);
}

const now = new Date();
const [year, month, day] = [
    now.getUTCFullYear(),
    now.getUTCMonth(),
    now.getUTCDate(),
];
const today = utcDay(year, month, day);

const presets = [
    { label: 'Today', from: today, to: today },
    { label: 'Last 7 days', from: utcDay(year, month, day - 6), to: today },
    { label: 'Last 30 days', from: utcDay(year, month, day - 29), to: today },
    { label: 'This month', from: utcDay(year, month, 1), to: today },
    {
        label: 'Last month',
        from: utcDay(year, month - 1, 1),
        to: utcDay(year, month, 0),
    },
];

// Only the first match lights up: on the 1st, "Today" and "This month" are the same day.
const active = computed(() =>
    presets.find(
        ({ from, to }) => from === props.range.from && to === props.range.to,
    ),
);
</script>

<template>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <nav
            aria-label="Quick ranges"
            class="flex flex-wrap gap-1 rounded-lg border bg-muted p-1"
        >
            <Link
                v-for="preset in presets"
                :key="preset.label"
                :href="report({ query: { from: preset.from, to: preset.to } })"
                :aria-current="preset === active ? 'true' : undefined"
                :class="[
                    'flex h-9 items-center rounded-md px-3 text-sm whitespace-nowrap transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                    preset === active
                        ? 'bg-background text-foreground shadow-xs'
                        : 'text-muted-foreground hover:bg-background/60 hover:text-foreground',
                ]"
            >
                {{ preset.label }}
            </Link>
        </nav>

        <Form v-bind="report.form()" class="flex flex-wrap items-end gap-3">
            <div class="grid gap-1.5">
                <Label for="from">From (UTC)</Label>
                <Input
                    id="from"
                    name="from"
                    type="date"
                    :default-value="range.from"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="to">To (UTC)</Label>
                <Input
                    id="to"
                    name="to"
                    type="date"
                    :default-value="range.to"
                />
            </div>
            <Button type="submit" variant="secondary">Show</Button>
        </Form>

        <p class="w-full text-xs text-muted-foreground">
            Days are UTC. Up to 92 days at a time, like Pixelforge's GET
            /v1/usage: a longer range keeps its last 92 days.
        </p>
    </div>
</template>
