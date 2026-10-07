<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { home } from '@/routes';
import { end } from '@/routes/sudo';
</script>

<template>
    <main class="flex min-h-svh flex-col items-center justify-center gap-4">
        <h1 class="text-xl font-semibold text-gray-900">Security settings</h1>
        <p class="text-gray-900">This page needs sudo. You have it.</p>
        <Form v-bind="end.form()">
            <button type="submit" class="text-sm font-medium text-gray-900 underline">End sudo</button>
        </Form>
        <Link :href="home()" class="text-sm text-gray-600 underline">Home</Link>
    </main>
</template>
