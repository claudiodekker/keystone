<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { submit } from '@/routes/register';
import type { RegisterPage } from '@/types/auth';

defineOptions({ layout: AuthLayout });

defineProps<RegisterPage>();
</script>

<template>
    <Head title="Create an account" />

    <h1 class="text-xl font-semibold text-gray-900">Create an account</h1>

    <p v-if="status" class="text-sm font-medium text-green-600">{{ status }}</p>

    <p v-if="mailsLink" class="text-sm text-gray-600">We'll email you a link to confirm the address is yours.</p>

    <Form v-bind="submit.form()" v-slot="{ errors, processing }" class="flex flex-col gap-4">
        <div class="flex flex-col gap-2">
            <label for="register-email" class="text-sm font-medium text-gray-900">Email address</label>
            <input
                id="register-email"
                name="email"
                type="email"
                autocomplete="email"
                :defaultValue="email ?? ''"
                required
                class="rounded-md border border-gray-300 px-3 py-2 text-sm"
            />
            <p v-if="errors.email" class="text-sm text-red-600">{{ errors.email }}</p>
        </div>

        <button type="submit" :disabled="processing" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
            {{ mailsLink ? 'Send me a link' : 'Continue' }}
        </button>
    </Form>

    <Link :href="login()" class="text-sm text-gray-600 underline">Already have an account? Sign in</Link>
</template>
