<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { sessionName } from '@/lib/sessions';
import { security } from '@/routes';
import { submit } from '@/routes/security/sessions/revoke';
import type { SessionRow } from '@/types/auth';

const props = defineProps<SessionRow>();

const name = sessionName(props);
</script>

<template>
    <Head title="Sign out a session" />

    <div class="flex min-h-svh justify-center bg-gray-50 p-6">
        <main class="flex w-full max-w-md flex-col gap-6 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
            <h1 class="text-xl font-semibold text-gray-900">Sign out this session?</h1>

            <p class="text-sm">
                <span class="font-medium text-gray-900">{{ name }}</span>
                <span class="block text-gray-600">
                    {{ ipAddress ?? 'Unknown IP address' }}<template v-if="location">, {{ location }}</template
                    >, last active
                    {{ new Date(lastActiveAt).toLocaleString() }}
                </span>
            </p>

            <p class="text-sm text-gray-600">
                That browser or device will be signed out, even if it remembers you. Your other sessions stay signed in.
            </p>

            <Form v-bind="submit.form(handle)" v-slot="{ processing }" class="flex items-center gap-4">
                <button type="submit" :disabled="processing" class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white">Sign out</button>
                <Link :href="security()" class="text-sm text-gray-600 underline">Keep it</Link>
            </Form>
        </main>
    </div>
</template>
