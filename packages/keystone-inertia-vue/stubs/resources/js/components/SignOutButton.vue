<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { logout } from '@/routes';
</script>

<template>
    <Link :href="logout()" as="button" class="text-sm font-medium text-gray-900 underline">Sign out</Link>
</template>
