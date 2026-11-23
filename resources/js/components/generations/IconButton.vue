<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

defineOptions({ inheritAttrs: false });

/** The label is both the accessible name and the tooltip. Other attributes (as, href, @click) go to the button. */
defineProps<{
    label: string;
}>();
</script>

<template>
    <!-- Not on programmatic focus, e.g. a dialog focusing its first button on open. -->
    <Tooltip ignore-non-keyboard-focus>
        <TooltipTrigger as-child>
            <Button
                variant="ghost"
                size="icon-sm"
                :aria-label="label"
                v-bind="$attrs"
            >
                <slot />
            </Button>
        </TooltipTrigger>
        <TooltipContent>{{ label }}</TooltipContent>
    </Tooltip>
</template>
