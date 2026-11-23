<script setup lang="ts">
import { useElementSize } from '@vueuse/core';
import { computed, ref, useTemplateRef } from 'vue';
import type { ReportDay } from '@/components/admin/report';
import { generationCount } from '@/components/admin/report';
import { formatUsd, formatUsdExact } from '@/lib/money';

/**
 * Credit spend per UTC day as stacked bars: provider cost at the bottom, profit on top.
 * The By day table is the accessible source of truth; this is the picture of it.
 */
const props = defineProps<{
    /** Newest first, as the report lists them. Never empty. */
    days: ReportDay[];
}>();

// Drawn in CSS pixels at the measured width, so bars and text stay crisp at any size.
const TOP = 8; // headroom for the top tick label
const PLOT = 152;
const BASELINE = TOP + PLOT;
const HEIGHT = BASELINE + 24; // plus the dates
const GAP = 2; // surface gap between a bar's cost and its profit
const CHAR = 6.6; // one 11px Chivo Mono character: the tick labels size the left gutter

const frame = useTemplateRef('frame');
const { width } = useElementSize(frame);

const shortDay = new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
    timeZone: 'UTC',
});
const longDay = new Intl.DateTimeFormat(undefined, {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    timeZone: 'UTC',
});

function formatDay(date: string, format = shortDay): string {
    return format.format(new Date(`${date}T00:00:00Z`));
}

const ordered = computed(() => [...props.days].reverse()); // oldest first: left to right
const busiest = computed(() =>
    ordered.value.reduce((top, day) =>
        day.spend_nanos > top.spend_nanos ? day : top,
    ),
);

/**
 * The busiest day rounded up to a round amount (1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6 or 8 times
 * a power of ten), so the ticks read like $0.12 or $2.50 and the tallest bar fills most of the plot.
 */
const axisTop = computed(() => {
    const peak = busiest.value.spend_nanos;
    const power = 10 ** Math.floor(Math.log10(Math.max(peak, 1)));

    return (
        [10, 12, 15, 20, 25, 30, 40, 50, 60, 80, 100]
            .map((tenths) => (tenths * power) / 10)
            .find((nice) => nice >= peak) ?? peak
    );
});

function toY(nanos: number): number {
    return BASELINE - (nanos / axisTop.value) * PLOT;
}

const ticks = computed(() =>
    [axisTop.value, axisTop.value / 2, 0].map((nanos) => ({
        nanos,
        y: toY(nanos),
        label: formatUsd(nanos),
    })),
);
const gutter = computed(
    () => Math.max(...ticks.value.map((tick) => tick.label.length)) * CHAR + 10,
);
const slot = computed(
    () => (width.value - gutter.value) / ordered.value.length,
);
const barWidth = computed(() => Math.min(24, slot.value * 0.7));

const bars = computed(() =>
    ordered.value.map((day, index) => {
        const x =
            gutter.value +
            index * slot.value +
            (slot.value - barWidth.value) / 2;
        const costTop = toY(day.provider_cost_nanos);
        const spendTop = toY(day.spend_nanos);

        return {
            day,
            x,
            costTop,
            profit: roundedTop(
                x,
                spendTop,
                barWidth.value,
                costTop - spendTop - GAP,
            ),
        };
    }),
);

/** A bar segment with 4px rounded top corners and a square bottom, as an SVG path. */
function roundedTop(x: number, top: number, w: number, h: number): string {
    if (h <= 0) {
        return '';
    }

    const r = Math.min(4, w / 2, h);

    return `M${x},${top + h}V${top + r}A${r},${r} 0 0 1 ${x + r},${top}H${x + w - r}A${r},${r} 0 0 1 ${x + w},${top + r}V${top + h}Z`;
}

// Every step-th day back from the newest, so the dates stay at least 64px apart.
const dateTicks = computed(() => {
    const count = ordered.value.length;
    const fit = Math.max(1, Math.floor((width.value - gutter.value) / 64));
    const step = Math.ceil(count / fit);

    return bars.value
        .filter((_, index) => (count - 1 - index) % step === 0)
        .map(({ day, x }) => ({
            date: day.date,
            text: formatDay(day.date),
            // Kept off the edges, where a centred date would be cut off.
            x: Math.min(
                Math.max(x + barWidth.value / 2, gutter.value + 20),
                width.value - 20,
            ),
        }));
});

const summary = computed(
    () =>
        `Credit spend per UTC day from ${formatDay(ordered.value[0].date)} to ${formatDay(props.days[0].date)}, each day split into provider cost and profit. ` +
        `Busiest day: ${formatDay(busiest.value.date)}, ${formatUsd(busiest.value.spend_nanos)}. ` +
        'Arrow keys step through the days; the By day table has the exact amounts.',
);

/** The day under the pointer or picked with the keyboard, as an index into `ordered`. */
const active = ref<number | null>(null);
const activeDay = computed(() =>
    active.value === null ? null : ordered.value[active.value],
);
const readout = computed(() => {
    const day = activeDay.value;

    return day
        ? [
              { label: 'Credit spend', nanos: day.spend_nanos, key: '' },
              {
                  label: 'Provider cost',
                  nanos: day.provider_cost_nanos,
                  key: 'bg-chart-3',
              },
              { label: 'Profit', nanos: day.profit_nanos, key: 'bg-chart-4' },
          ]
        : [];
});

// The whole column is the hit target, not just the painted bar.
function pointAt(event: PointerEvent): void {
    const left = frame.value?.getBoundingClientRect().left ?? 0;
    const index = Math.floor(
        (event.clientX - left - gutter.value) / slot.value,
    );

    active.value = Math.min(Math.max(index, 0), ordered.value.length - 1);
}

function pointerLeft(event: PointerEvent): void {
    // A tap on a touch screen ends with pointerleave: keep the tapped day showing.
    if (event.pointerType === 'mouse') {
        active.value = null;
    }
}

function focusNewest(): void {
    active.value ??= ordered.value.length - 1;
}

function stepThrough(event: KeyboardEvent): void {
    const last = ordered.value.length - 1;
    const current = active.value ?? last;
    const targets: Record<string, number> = {
        ArrowLeft: current - 1,
        ArrowRight: current + 1,
        Home: 0,
        End: last,
    };

    if (event.key in targets) {
        event.preventDefault();
        active.value = Math.min(Math.max(targets[event.key], 0), last);
    }
}
</script>

<template>
    <div class="grid gap-3 rounded-xl border bg-card p-4">
        <p
            v-if="busiest.spend_nanos === 0"
            class="grid place-items-center rounded-lg border border-dashed bg-grain p-4 text-center text-sm text-muted-foreground"
            :style="{ height: `${HEIGHT}px` }"
        >
            Nothing was billed in this range, so there's nothing to chart.
        </p>

        <template v-else>
            <ul
                class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
            >
                <li class="flex items-center gap-1.5">
                    <span class="size-2.5 rounded-sm bg-chart-3" />
                    Provider cost
                </li>
                <li class="flex items-center gap-1.5">
                    <span class="size-2.5 rounded-sm bg-chart-4" />
                    Profit
                </li>
            </ul>

            <!-- The SVG is absolute so its pixel width never holds the frame open while it shrinks. -->
            <div
                ref="frame"
                class="relative"
                :style="{ height: `${HEIGHT}px` }"
            >
                <svg
                    v-if="width > 0"
                    :width="width"
                    :height="HEIGHT"
                    role="img"
                    :aria-label="summary"
                    tabindex="0"
                    class="absolute top-0 left-0 touch-pan-y overflow-visible rounded-md outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    @pointerdown="pointAt"
                    @pointermove="pointAt"
                    @pointerleave="pointerLeft"
                    @focus="focusNewest"
                    @blur="active = null"
                    @keydown="stepThrough"
                >
                    <rect
                        v-if="active !== null"
                        :x="gutter + active * slot"
                        :y="TOP"
                        :width="slot"
                        :height="PLOT"
                        class="fill-accent"
                    />

                    <g class="stroke-border" shape-rendering="crispEdges">
                        <line
                            v-for="tick in ticks"
                            :key="tick.nanos"
                            :x1="gutter"
                            :x2="width"
                            :y1="tick.y"
                            :y2="tick.y"
                        />
                    </g>

                    <g
                        v-for="(bar, index) in bars"
                        :key="bar.day.date"
                        class="transition-opacity"
                        :class="{
                            'opacity-40': active !== null && active !== index,
                        }"
                    >
                        <rect
                            :x="bar.x"
                            :y="bar.costTop"
                            :width="barWidth"
                            :height="BASELINE - bar.costTop"
                            class="fill-chart-3"
                        />
                        <path
                            v-if="bar.profit"
                            :d="bar.profit"
                            class="fill-chart-4"
                        />
                    </g>

                    <line
                        :x1="gutter"
                        :x2="width"
                        :y1="BASELINE"
                        :y2="BASELINE"
                        class="stroke-ash"
                        shape-rendering="crispEdges"
                    />

                    <g
                        class="fill-muted-foreground font-mono text-[11px] tabular-nums"
                    >
                        <text
                            v-for="tick in ticks"
                            :key="tick.nanos"
                            :x="gutter - 8"
                            :y="tick.y"
                            text-anchor="end"
                            dominant-baseline="middle"
                        >
                            {{ tick.label }}
                        </text>
                    </g>
                    <g class="fill-muted-foreground text-[11px]">
                        <text
                            v-for="dateTick in dateTicks"
                            :key="dateTick.date"
                            :x="dateTick.x"
                            :y="BASELINE + 16"
                            text-anchor="middle"
                        >
                            {{ dateTick.text }}
                        </text>
                    </g>
                </svg>
            </div>

            <p
                class="flex min-h-5 flex-wrap items-center gap-x-4 gap-y-1 text-sm"
                aria-live="polite"
            >
                <template v-if="activeDay">
                    <span class="font-medium">
                        {{ formatDay(activeDay.date, longDay) }}
                    </span>
                    <span class="text-muted-foreground">
                        {{ generationCount(activeDay.generations) }}
                    </span>
                    <span
                        v-for="item in readout"
                        :key="item.label"
                        class="flex items-center gap-1.5"
                    >
                        <span
                            v-if="item.key"
                            class="h-0.5 w-3 rounded-full"
                            :class="item.key"
                        />
                        <span class="text-muted-foreground">{{
                            item.label
                        }}</span>
                        <span
                            class="font-mono tabular-nums"
                            :title="formatUsdExact(item.nanos)"
                        >
                            {{ formatUsd(item.nanos) }}
                        </span>
                    </span>
                </template>
                <span v-else class="text-muted-foreground">
                    Hover or tap a day for its numbers, or focus the chart and
                    use the arrow keys.
                </span>
            </p>
        </template>
    </div>
</template>
