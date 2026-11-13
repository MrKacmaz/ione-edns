<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useConnectionStatus, useEcho } from '@laravel/echo-vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import StatTile from '@/components/dashboard/StatTile.vue';
import StatusBadge from '@/components/dashboard/StatusBadge.vue';
import {
    formatAge,
    formatCount,
    formatMilliseconds,
    formatRatio,
    formatSince,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import { logout } from '@/routes';
import type {
    CircuitChangedEvent,
    MetricsSnapshot,
    MetricsUpdatedEvent,
    NotificationRow,
    NotificationStatusChangedEvent,
} from '@/types';

const FEED_SIZE = 20;

const props = defineProps<{
    metrics: MetricsSnapshot;
    notifications: NotificationRow[];
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);

const metrics = ref<MetricsSnapshot>(props.metrics);
const notifications = ref<NotificationRow[]>([...props.notifications]);
const highlightedId = ref<string | null>(null);
const selectedWindow = ref(props.metrics.windows[0]?.window ?? '5m');

const now = ref(Date.now());
let clock: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    clock = setInterval(() => {
        now.value = Date.now();
    }, 1000);
});

onUnmounted(() => clearInterval(clock));

const connectionStatus = useConnectionStatus();

const connection = computed(() => {
    switch (connectionStatus.value) {
        case 'connected':
            return { label: 'Live', dot: 'bg-emerald-500' };
        case 'connecting':
        case 'reconnecting':
            return { label: 'Connecting…', dot: 'bg-amber-500' };
        default:
            return { label: 'Offline', dot: 'bg-rose-500' };
    }
});

useEcho<MetricsUpdatedEvent>('operators', '.metrics.updated', (event) => {
    metrics.value = event.metrics;
});

useEcho<CircuitChangedEvent>('operators', '.circuit.changed', (event) => {
    metrics.value = {
        ...metrics.value,
        circuits: { ...metrics.value.circuits, [event.channel]: event.state },
    };
});

useEcho<NotificationStatusChangedEvent>(
    `App.Models.User.${user.value.id}`,
    '.notification.status-changed',
    (event) => {
        const known = notifications.value.find(
            (notification) => notification.id === event.notification.id,
        );
        const others = notifications.value.filter(
            (notification) => notification.id !== event.notification.id,
        );

        notifications.value = [
            { ...known, ...event.notification },
            ...others,
        ].slice(0, FEED_SIZE);
        highlightedId.value = event.notification.id;
    },
);

const currentWindow = computed(
    () =>
        metrics.value.windows.find(
            (window) => window.window === selectedWindow.value,
        ) ?? metrics.value.windows[0],
);

const inFlightTotal = computed(() =>
    Object.values(metrics.value.notifications_in_flight).reduce(
        (total, count) => total + count,
        0,
    ),
);
</script>

<template>
    <Head title="Dashboard" />
    <div class="min-h-screen bg-zinc-50 dark:bg-zinc-950">
        <header
            class="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900"
        >
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-4"
            >
                <div class="flex items-center gap-3">
                    <h1
                        class="text-base font-semibold text-zinc-900 dark:text-zinc-50"
                    >
                        EDNS operations
                    </h1>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full border border-zinc-200 px-2 py-0.5 text-xs text-zinc-600 dark:border-zinc-700 dark:text-zinc-300"
                        data-test="connection-status"
                    >
                        <span
                            :class="cn('size-2 rounded-full', connection.dot)"
                        />
                        {{ connection.label }}
                    </span>
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                        Updated {{ formatSince(metrics.generated_at, now) }}
                    </span>
                </div>
                <div class="flex items-center gap-4">
                    <span class="text-sm text-zinc-600 dark:text-zinc-300">
                        {{ user.name }}
                    </span>
                    <Link
                        :href="logout()"
                        as="button"
                        class="rounded-md border border-zinc-300 px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
                    >
                        Sign out
                    </Link>
                </div>
            </div>
        </header>

        <main class="mx-auto flex max-w-6xl flex-col gap-8 px-6 py-8">
            <section class="flex flex-col gap-3">
                <h2
                    class="text-sm font-semibold text-zinc-700 dark:text-zinc-300"
                >
                    Delivery circuits
                </h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div
                        v-for="(state, channel) in metrics.circuits"
                        :key="channel"
                        class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900"
                    >
                        <span
                            class="text-sm font-medium text-zinc-900 uppercase dark:text-zinc-50"
                        >
                            {{ channel }}
                        </span>
                        <StatusBadge :status="state" />
                    </div>
                </div>
            </section>

            <section v-if="currentWindow" class="flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <h2
                        class="text-sm font-semibold text-zinc-700 dark:text-zinc-300"
                    >
                        Deliveries
                    </h2>
                    <div
                        class="inline-flex rounded-md border border-zinc-200 bg-white p-0.5 dark:border-zinc-800 dark:bg-zinc-900"
                    >
                        <button
                            v-for="window in metrics.windows"
                            :key="window.window"
                            type="button"
                            :class="
                                cn(
                                    'rounded px-3 py-1 text-xs font-medium',
                                    window.window === selectedWindow
                                        ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                                        : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100',
                                )
                            "
                            @click="selectedWindow = window.window"
                        >
                            Last {{ window.window }}
                        </button>
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatTile
                        label="Success rate"
                        :value="formatRatio(currentWindow.success_rate)"
                        :hint="`${formatCount(currentWindow.notifications.sent)} sent, ${formatCount(currentWindow.notifications.failed)} failed`"
                    />
                    <StatTile
                        label="Provider calls"
                        :value="formatCount(currentWindow.attempts.total)"
                        :hint="`${formatCount(currentWindow.attempts.retryable_failure)} retryable, ${formatCount(currentWindow.attempts.permanent_failure)} permanent failures`"
                    />
                    <StatTile
                        label="Provider latency p95"
                        :value="
                            formatMilliseconds(
                                currentWindow.latency_ms.provider.p95,
                            )
                        "
                        :hint="`p50 ${formatMilliseconds(currentWindow.latency_ms.provider.p50)} · p99 ${formatMilliseconds(currentWindow.latency_ms.provider.p99)}`"
                    />
                    <StatTile
                        label="End-to-end latency p95"
                        :value="
                            formatMilliseconds(
                                currentWindow.latency_ms.end_to_end.p95,
                            )
                        "
                        :hint="`p50 ${formatMilliseconds(currentWindow.latency_ms.end_to_end.p50)} · p99 ${formatMilliseconds(currentWindow.latency_ms.end_to_end.p99)}`"
                    />
                </div>
            </section>

            <div class="grid gap-8 lg:grid-cols-2">
                <section class="flex flex-col gap-3">
                    <h2
                        class="text-sm font-semibold text-zinc-700 dark:text-zinc-300"
                    >
                        Queues
                    </h2>
                    <div
                        class="overflow-x-auto rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900"
                    >
                        <table class="w-full text-left text-sm">
                            <thead
                                class="border-b border-zinc-200 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400"
                            >
                                <tr>
                                    <th class="px-4 py-2 font-medium">Queue</th>
                                    <th
                                        class="px-4 py-2 text-right font-medium"
                                    >
                                        Pending
                                    </th>
                                    <th
                                        class="px-4 py-2 text-right font-medium"
                                    >
                                        Delayed
                                    </th>
                                    <th
                                        class="px-4 py-2 text-right font-medium"
                                    >
                                        Reserved
                                    </th>
                                    <th
                                        class="px-4 py-2 text-right font-medium"
                                    >
                                        Oldest
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-zinc-100 text-zinc-800 tabular-nums dark:divide-zinc-800 dark:text-zinc-200"
                            >
                                <tr
                                    v-for="queue in metrics.queues"
                                    :key="queue.queue"
                                >
                                    <td class="px-4 py-2 whitespace-nowrap">
                                        {{ queue.queue }}
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        {{ formatCount(queue.pending) }}
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        {{ formatCount(queue.delayed) }}
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        {{ formatCount(queue.reserved) }}
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        {{
                                            formatAge(
                                                queue.oldest_pending_age_seconds,
                                            )
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="flex flex-col gap-3">
                    <h2
                        class="text-sm font-semibold text-zinc-700 dark:text-zinc-300"
                    >
                        Not finished yet
                        <span class="font-normal text-zinc-500">
                            ({{ formatCount(inFlightTotal) }})
                        </span>
                    </h2>
                    <div class="grid grid-cols-2 gap-4">
                        <div
                            v-for="(
                                count, status
                            ) in metrics.notifications_in_flight"
                            :key="status"
                            class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900"
                        >
                            <StatusBadge :status="status" />
                            <span
                                class="text-lg font-semibold text-zinc-900 tabular-nums dark:text-zinc-50"
                            >
                                {{ formatCount(count) }}
                            </span>
                        </div>
                    </div>
                </section>
            </div>

            <section class="flex flex-col gap-3">
                <h2
                    class="text-sm font-semibold text-zinc-700 dark:text-zinc-300"
                >
                    Your latest notifications
                </h2>
                <div
                    class="overflow-x-auto rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900"
                >
                    <p
                        v-if="notifications.length === 0"
                        class="px-4 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        No notifications yet. Notifications you send through the
                        API show up here as they finish.
                    </p>
                    <table v-else class="w-full text-left text-sm">
                        <thead
                            class="border-b border-zinc-200 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400"
                        >
                            <tr>
                                <th class="px-4 py-2 font-medium">Status</th>
                                <th class="px-4 py-2 font-medium">Channel</th>
                                <th class="px-4 py-2 font-medium">Recipient</th>
                                <th class="px-4 py-2 text-right font-medium">
                                    Attempts
                                </th>
                                <th class="px-4 py-2 font-medium">Updated</th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-zinc-100 text-zinc-800 dark:divide-zinc-800 dark:text-zinc-200"
                        >
                            <tr
                                v-for="notification in notifications"
                                :key="notification.id"
                                :class="
                                    cn(
                                        'transition-colors duration-700',
                                        notification.id === highlightedId &&
                                            'bg-amber-50 dark:bg-amber-950/40',
                                    )
                                "
                            >
                                <td class="px-4 py-2">
                                    <StatusBadge
                                        :status="notification.status"
                                    />
                                </td>
                                <td class="px-4 py-2 uppercase">
                                    {{ notification.channel }}
                                </td>
                                <td
                                    class="max-w-56 truncate px-4 py-2"
                                    :title="
                                        notification.last_error ?? undefined
                                    "
                                >
                                    {{ notification.recipient }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ notification.attempts }}
                                </td>
                                <td
                                    class="px-4 py-2 text-zinc-500 dark:text-zinc-400"
                                >
                                    {{
                                        formatSince(
                                            notification.updated_at,
                                            now,
                                        )
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</template>
