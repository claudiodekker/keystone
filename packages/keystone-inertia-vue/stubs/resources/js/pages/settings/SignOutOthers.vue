<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { security } from '@/routes';
import { submit } from '@/routes/security/sessions/others/revoke';
</script>

<template>
    <Head title="Sign out other sessions" />

    <div class="flex min-h-svh justify-center bg-gray-50 p-6">
        <main class="flex w-full max-w-md flex-col gap-6 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
            <h1 class="text-xl font-semibold text-gray-900">Sign out other sessions?</h1>

            <p class="text-sm text-gray-600">
                Every other browser and device signed in to your account will be signed out, including ones that remember you. This one stays signed
                in.
            </p>

            <Form v-bind="submit.form()" v-slot="{ processing }" class="flex items-center gap-4">
                <button type="submit" :disabled="processing" class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white">
                    Sign out other sessions
                </button>
                <Link :href="security()" class="text-sm text-gray-600 underline">Cancel</Link>
            </Form>
        </main>
    </div>
</template>
