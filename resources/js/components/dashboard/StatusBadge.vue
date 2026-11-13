<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import type { CircuitState, NotificationStatus } from '@/types';

const props = defineProps<{
    status: NotificationStatus | CircuitState;
}>();

const tones: Record<NotificationStatus | CircuitState, string> = {
    waiting: 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
    scheduled:
        'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300',
    queued: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    processing:
        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    sent: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    failed: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
    cancelled: 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
    closed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    open: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
    half_open:
        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
};

const label = computed(() => props.status.replace('_', ' '));
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize',
                tones[status],
            )
        "
    >
        {{ label }}
    </span>
</template>
