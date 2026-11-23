<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<template>
    <!-- Always in the page (v-show), so screen readers announce an error as it appears. -->
    <p v-show="message" role="alert" class="text-sm text-destructive">
        {{ message }}
    </p>
</template>
