<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { cancel, confirm } from '@/routes/security/recovery-codes/regenerate';
import type { RegenerateRecoveryCodesPage } from '@/types/auth';

defineProps<RegenerateRecoveryCodesPage>();
</script>

<template>
    <Head title="Regenerate recovery codes" />

    <div class="flex min-h-svh justify-center bg-gray-50 p-6">
        <main class="flex w-full max-w-md flex-col gap-6 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
            <h1 class="text-xl font-semibold text-gray-900">Regenerate recovery codes</h1>

            <p v-if="status" class="text-sm font-medium text-red-600">{{ status }}</p>

            <p class="text-sm text-gray-600">
                Each code signs you in once if you lose your second factor. Store them somewhere safe: you won't see them again.
            </p>

            <p v-if="replaces" class="text-sm font-medium text-amber-700">
                Saving these replaces your current recovery codes, which will stop working. Your other sessions will be signed out.
            </p>

            <ul class="grid grid-cols-2 gap-2 rounded-md bg-gray-100 p-4 font-mono text-sm text-gray-900">
                <li v-for="code in codes" :key="code">{{ code }}</li>
            </ul>

            <Form v-bind="confirm.form()" :reset-on-error="['code']" v-slot="{ errors, processing }" class="flex flex-col gap-4">
                <div class="flex flex-col gap-2">
                    <label for="recovery-code" class="text-sm font-medium text-gray-900">Type one of the codes to confirm you saved them</label>
                    <input
                        id="recovery-code"
                        name="code"
                        type="text"
                        autocomplete="off"
                        autocapitalize="characters"
                        spellcheck="false"
                        required
                        class="rounded-md border border-gray-300 px-3 py-2 font-mono text-sm"
                    />
                    <p v-if="errors.code" class="text-sm text-red-600">{{ errors.code }}</p>
                </div>

                <button
                    type="submit"
                    :disabled="processing"
                    class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                >
                    I saved them
                </button>
            </Form>

            <Form v-bind="cancel.form()">
                <button type="submit" class="text-sm text-gray-600 underline">Cancel</button>
            </Form>
        </main>
    </div>
</template>
