<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChartColumn, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavCredits from '@/components/NavCredits.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { report } from '@/routes/admin';
import { index } from '@/routes/generations';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Generate', href: index(), icon: Sparkles },
    ...(page.props.auth.user.is_admin
        ? [{ title: 'Admin report', href: report(), icon: ChartColumn }]
        : []),
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="index()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavCredits />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
