<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { store } from '@/routes/login';

const inputClass =
    'w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-zinc-500 focus:ring-2 focus:ring-zinc-200 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:ring-zinc-800';
</script>

<template>
    <Head title="Sign in" />
    <div
        class="flex min-h-screen items-center justify-center bg-zinc-50 p-6 dark:bg-zinc-950"
    >
        <div
            class="flex w-full max-w-sm flex-col gap-6 rounded-xl border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
        >
            <div class="flex flex-col gap-1">
                <h1
                    class="text-lg font-semibold text-zinc-900 dark:text-zinc-50"
                >
                    EDNS operations
                </h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Sign in with an operator account to watch deliveries live.
                </p>
            </div>

            <Form
                v-bind="store.form()"
                :reset-on-error="['password']"
                class="flex flex-col gap-4"
                #default="{ errors, processing }"
            >
                <label class="flex flex-col gap-1.5">
                    <span
                        class="text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Email
                    </span>
                    <input
                        v-focus
                        type="email"
                        name="email"
                        autocomplete="username"
                        required
                        :class="inputClass"
                    />
                    <span
                        v-if="errors.email"
                        class="text-sm text-rose-600 dark:text-rose-400"
                    >
                        {{ errors.email }}
                    </span>
                </label>

                <label class="flex flex-col gap-1.5">
                    <span
                        class="text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Password
                    </span>
                    <input
                        type="password"
                        name="password"
                        autocomplete="current-password"
                        required
                        :class="inputClass"
                    />
                    <span
                        v-if="errors.password"
                        class="text-sm text-rose-600 dark:text-rose-400"
                    >
                        {{ errors.password }}
                    </span>
                </label>

                <label
                    class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400"
                >
                    <input type="checkbox" name="remember" value="1" />
                    Keep me signed in
                </label>

                <button
                    type="submit"
                    :disabled="processing"
                    class="rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-60 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300"
                >
                    {{ processing ? 'Signing in…' : 'Sign in' }}
                </button>
            </Form>
        </div>
    </div>
</template>
