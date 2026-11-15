<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { store } from '@/routes/dashboard/notifications';
import type { DashboardTemplate } from '@/types';

const props = defineProps<{
    templates: DashboardTemplate[];
    maxBatchSize: number;
}>();

const CHANNELS = ['sms', 'email', 'push'] as const;
const PRIORITIES = ['high', 'normal', 'low'] as const;

type Channel = (typeof CHANNELS)[number];

/**
 * A valid recipient per channel, filled in so a test send needs no typing.
 */
const EXAMPLE_RECIPIENTS: Record<Channel, string> = {
    sms: '+905551234567',
    email: 'customer@example.com',
    push: 'device-token-0123456789abcdef',
};

/**
 * Markers the Compose mock provider understands; appended to the content.
 */
const FAULTS = [
    { marker: '', label: 'None, accept it' },
    { marker: '[fail:400]', label: 'Reject with 400 (fails at once)' },
    { marker: '[fail:503*1]', label: '503 once, then accept (one retry)' },
    { marker: '[fail:503]', label: 'Always 503 (retried until it fails)' },
    { marker: '[slow:2000]', label: 'Answer after 2 seconds' },
    { marker: '[slow:11000]', label: 'Time out (retried)' },
] as const;

const usesTemplate = ref(false);
const fault = ref('');

const form = useForm({
    channel: 'sms' as Channel,
    recipient: EXAMPLE_RECIPIENTS.sms,
    content: 'Hello from the EDNS dashboard',
    template: '',
    variables: {} as Record<string, string>,
    priority: 'normal',
    count: 1,
});

const channelTemplates = computed(() =>
    props.templates.filter((template) => template.channel === form.channel),
);

const selectedTemplate = computed(() =>
    channelTemplates.value.find((template) => template.name === form.template),
);

watch(
    () => form.channel,
    (channel, previous) => {
        if (form.recipient === EXAMPLE_RECIPIENTS[previous]) {
            form.recipient = EXAMPLE_RECIPIENTS[channel];
        }

        useTemplates(usesTemplate.value);
    },
);

watch(selectedTemplate, (template) => {
    form.variables = Object.fromEntries(
        (template?.variables ?? []).map((name) => [
            name,
            form.variables[name] ?? '',
        ]),
    );
});

function submit(): void {
    form.transform((data) => {
        const common = {
            channel: data.channel,
            recipient: data.recipient,
            priority: data.priority,
            count: data.count,
        };

        return usesTemplate.value
            ? {
                  ...common,
                  template: data.template,
                  variables: data.variables,
              }
            : {
                  ...common,
                  content: [data.content, fault.value]
                      .filter(Boolean)
                      .join(' '),
              };
    }).submit(store(), { preserveScroll: true });
}

function useTemplates(enabled: boolean): void {
    usesTemplate.value = enabled;
    form.template = enabled ? (channelTemplates.value[0]?.name ?? '') : '';
}

const variableErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('variables'))
        .map(([, message]) => message),
);

const labelClass = 'text-xs font-medium text-zinc-600 dark:text-zinc-400';
const inputClass =
    'w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-zinc-500 focus:ring-2 focus:ring-zinc-200 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:ring-zinc-800';
const errorClass = 'text-xs text-rose-600 dark:text-rose-400';
</script>

<template>
    <form
        class="grid gap-4 rounded-lg border border-zinc-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4 dark:border-zinc-800 dark:bg-zinc-900"
        data-test="send-form"
        @submit.prevent="submit"
    >
        <label class="flex flex-col gap-1.5">
            <span :class="labelClass">Channel</span>
            <select v-model="form.channel" name="channel" :class="inputClass">
                <option
                    v-for="channel in CHANNELS"
                    :key="channel"
                    :value="channel"
                >
                    {{ channel.toUpperCase() }}
                </option>
            </select>
            <span v-if="form.errors.channel" :class="errorClass">
                {{ form.errors.channel }}
            </span>
        </label>

        <label class="flex flex-col gap-1.5 sm:col-span-1 lg:col-span-2">
            <span :class="labelClass">Recipient</span>
            <input
                v-model="form.recipient"
                name="recipient"
                type="text"
                required
                :class="inputClass"
            />
            <span v-if="form.errors.recipient" :class="errorClass">
                {{ form.errors.recipient }}
            </span>
        </label>

        <label class="flex flex-col gap-1.5">
            <span :class="labelClass">Priority</span>
            <select v-model="form.priority" name="priority" :class="inputClass">
                <option
                    v-for="priority in PRIORITIES"
                    :key="priority"
                    :value="priority"
                >
                    {{ priority }}
                </option>
            </select>
            <span v-if="form.errors.priority" :class="errorClass">
                {{ form.errors.priority }}
            </span>
        </label>

        <div class="flex flex-col gap-1.5 sm:col-span-2 lg:col-span-3">
            <div class="flex items-center gap-3">
                <span :class="labelClass">Message</span>
                <div
                    class="inline-flex rounded-md border border-zinc-200 p-0.5 dark:border-zinc-800"
                >
                    <button
                        v-for="option in [
                            { label: 'Text', templated: false },
                            { label: 'Template', templated: true },
                        ]"
                        :key="option.label"
                        type="button"
                        :class="[
                            'rounded px-2.5 py-0.5 text-xs font-medium',
                            usesTemplate === option.templated
                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                                : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100',
                        ]"
                        @click="useTemplates(option.templated)"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </div>

            <template v-if="!usesTemplate">
                <textarea
                    v-model="form.content"
                    name="content"
                    rows="2"
                    required
                    :class="inputClass"
                />
                <span v-if="form.errors.content" :class="errorClass">
                    {{ form.errors.content }}
                </span>
            </template>

            <template v-else>
                <p
                    v-if="channelTemplates.length === 0"
                    class="text-sm text-zinc-500 dark:text-zinc-400"
                >
                    You have no {{ form.channel.toUpperCase() }} templates.
                    Create one with <code>POST /api/v1/templates</code>.
                </p>
                <div v-else class="grid gap-3 sm:grid-cols-2">
                    <select
                        v-model="form.template"
                        name="template"
                        :class="inputClass"
                    >
                        <option
                            v-for="template in channelTemplates"
                            :key="template.name"
                            :value="template.name"
                        >
                            {{ template.name }}
                        </option>
                    </select>
                    <input
                        v-for="name in selectedTemplate?.variables ?? []"
                        :key="name"
                        v-model="form.variables[name]"
                        :name="`variables[${name}]`"
                        :placeholder="name"
                        :aria-label="`Value of ${name}`"
                        type="text"
                        :class="inputClass"
                    />
                </div>
                <span v-if="form.errors.template" :class="errorClass">
                    {{ form.errors.template }}
                </span>
                <span
                    v-for="message in variableErrors"
                    :key="message"
                    :class="errorClass"
                >
                    {{ message }}
                </span>
            </template>
        </div>

        <label class="flex flex-col gap-1.5">
            <span :class="labelClass">Copies (one batch above 1)</span>
            <input
                v-model.number="form.count"
                name="count"
                type="number"
                min="1"
                :max="maxBatchSize"
                required
                :class="inputClass"
            />
            <span v-if="form.errors.count" :class="errorClass">
                {{ form.errors.count }}
            </span>
        </label>

        <label class="flex flex-col gap-1.5 sm:col-span-2 lg:col-span-3">
            <span :class="labelClass">
                Provider fault
                <span class="font-normal text-zinc-500">
                    (understood by the Compose mock provider only)
                </span>
            </span>
            <select
                v-model="fault"
                name="fault"
                :disabled="usesTemplate"
                :class="inputClass"
            >
                <option
                    v-for="option in FAULTS"
                    :key="option.marker"
                    :value="option.marker"
                >
                    {{ option.label
                    }}{{ option.marker ? ` ${option.marker}` : '' }}
                </option>
            </select>
        </label>

        <div class="flex items-end">
            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-60 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300"
            >
                {{ form.processing ? 'Sending…' : 'Send' }}
            </button>
        </div>
    </form>
</template>
