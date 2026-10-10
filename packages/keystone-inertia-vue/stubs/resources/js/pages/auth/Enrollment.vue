<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { cancel, start } from '@/routes/login/enrollment';
import type { EnrollmentPage } from '@/types/auth';

defineOptions({ layout: AuthLayout });

defineProps<EnrollmentPage>();

const page = usePage<{ errors: Partial<Record<string, string>> }>();
</script>

<template>
    <Head title="Set up two-factor authentication" />

    <h1 class="text-xl font-semibold text-gray-900">Set up two-factor authentication</h1>

    <p class="text-sm text-gray-600">Your account needs a second way to confirm it's you. Choose one to set up:</p>

    <div class="flex flex-col gap-2">
        <div v-for="option in types" :key="option.type" class="flex flex-col gap-1">
            <Link :href="start({ type: option.type })" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-900">
                {{ option.type }}
            </Link>
            <p v-if="page.props.errors[option.type]" class="text-sm text-red-600">{{ page.props.errors[option.type] }}</p>
        </div>
    </div>

    <Form v-bind="cancel.form()">
        <button type="submit" class="text-sm text-gray-600 underline">{{ origin === 'registration' ? 'Sign out' : 'Cancel sign-in' }}</button>
    </Form>
</template>
