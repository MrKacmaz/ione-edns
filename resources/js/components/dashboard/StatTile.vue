<script setup lang="ts">
defineProps<{
    label: string;
    value: string;
    hint?: string;
}>();
</script>

<template>
    <div
        class="flex flex-col gap-1 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900"
    >
        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">
            {{ label }}
        </span>
        <span
            class="text-2xl font-semibold text-zinc-900 tabular-nums dark:text-zinc-50"
        >
            {{ value }}
        </span>
        <span v-if="hint" class="text-xs text-zinc-500 dark:text-zinc-400">
            {{ hint }}
        </span>
    </div>
</template>
