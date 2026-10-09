<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import type { EmailedLinkPage } from '@/types/auth';

defineOptions({ layout: AuthLayout });

defineProps<EmailedLinkPage>();
</script>

<template>
    <Head title="Continue from your email" />

    <h1 class="text-xl font-semibold text-gray-900">Continue from your email</h1>

    <p class="text-sm text-gray-600">You opened a link we emailed you. Choose Continue to use it. It works only once.</p>

    <Form :action="action" method="post" v-slot="{ processing }">
        <button
            type="submit"
            :disabled="processing"
            class="w-full rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
        >
            Continue
        </button>
    </Form>
</template>
