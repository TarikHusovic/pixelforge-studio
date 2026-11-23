<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/composables/useAppearance';

const { appearance, updateAppearance } = useAppearance();

const options = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
    { value: 'system', Icon: Monitor, label: 'System' },
] as const;

const current = computed(
    () => options.find(({ value }) => value === appearance.value) ?? options[2],
);
</script>

<template>
    <!-- No Tooltip around the trigger: nesting two reka triggers loses the menu's anchor. -->
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon-sm"
                :aria-label="`Theme: ${current.label}`"
                :title="`Theme: ${current.label}`"
            >
                <component :is="current.Icon" />
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="min-w-36">
            <DropdownMenuRadioGroup :model-value="appearance">
                <DropdownMenuRadioItem
                    v-for="{ value, Icon, label } in options"
                    :key="value"
                    :value="value"
                    @select="updateAppearance(value)"
                >
                    <component :is="Icon" />
                    {{ label }}
                </DropdownMenuRadioItem>
            </DropdownMenuRadioGroup>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
