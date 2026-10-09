<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { register } from '@/routes';

defineOptions({ layout: AuthLayout });
</script>

<template>
    <Head title="Check your email" />

    <h1 class="text-xl font-semibold text-gray-900">Check your email</h1>

    <p class="text-sm text-gray-600">
        If that address can be used to create an account, we've sent it a link. Open it within 10 minutes to carry on. If you already have an account,
        sign in instead.
    </p>

    <Link :href="register()" class="text-sm text-gray-600 underline">Use another address</Link>
</template>
