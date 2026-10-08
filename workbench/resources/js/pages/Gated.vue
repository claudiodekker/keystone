<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { home } from '@/routes';
</script>

<template>
    <main class="flex min-h-svh flex-col items-center justify-center gap-4">
        <h1 class="text-xl font-semibold text-gray-900">A page behind sudo</h1>
        <p class="text-gray-900">This page needs sudo. You have it.</p>
        <Link :href="home()" class="text-sm text-gray-600 underline">Home</Link>
    </main>
</template>
