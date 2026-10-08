<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { security } from '@/routes';
import { submit } from '@/routes/security/credentials/remove';
import type { CredentialRemovalPage } from '@/types/auth';

const props = defineProps<CredentialRemovalPage>();

const typeNames: Record<string, string> = {
    password: 'Password',
    totp: 'Authenticator app',
};

const name = props.label ?? typeNames[props.type] ?? props.type;
</script>

<template>
    <Head title="Remove a credential" />

    <div class="flex min-h-svh justify-center bg-gray-50 p-6">
        <main class="flex w-full max-w-md flex-col gap-6 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
            <h1 class="text-xl font-semibold text-gray-900">Remove {{ name }}?</h1>

            <p v-if="listed" class="text-sm text-gray-600">
                You won't be able to sign in or confirm it's you with it any more. Your other sessions will be signed out.
            </p>
            <p v-else class="text-sm text-gray-600">This app no longer accepts it. Your other sessions will be signed out.</p>

            <Form v-bind="submit.form(id)" v-slot="{ errors, processing }" class="flex flex-col gap-4">
                <p v-if="errors.credential" class="text-sm text-red-600">{{ errors.credential }}</p>
                <div class="flex items-center gap-4">
                    <button type="submit" :disabled="processing" class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white">
                        Remove
                    </button>
                    <Link :href="security()" class="text-sm text-gray-600 underline">Keep it</Link>
                </div>
            </Form>
        </main>
    </div>
</template>
