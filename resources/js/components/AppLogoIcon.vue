<script setup lang="ts">
defineOptions({
    inheritAttrs: false,
});
</script>

<template>
    <!-- Three cool pixels and one being forged. -->
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" v-bind="$attrs">
        <path fill="currentColor" d="M2 2h9v9H2zM13 2h9v9h-9zM2 13h9v9H2z" />
        <path class="fill-ember" d="M14.5 14.5h6v6h-6z" />
    </svg>
</template>
