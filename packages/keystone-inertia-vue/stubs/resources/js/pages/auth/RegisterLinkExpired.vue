<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { register } from '@/routes';

defineOptions({ layout: AuthLayout });
</script>

<template>
    <Head title="Link no longer valid" />

    <h1 class="text-xl font-semibold text-gray-900">That link is no longer valid</h1>

    <p class="text-sm text-gray-600">Links work once, for 10 minutes. Ask for a new one to carry on.</p>

    <Link :href="register()" class="text-sm font-medium text-gray-900 underline">Send me a new link</Link>
</template>
