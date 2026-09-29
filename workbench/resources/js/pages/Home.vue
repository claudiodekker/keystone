<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import SignOutButton from '@/components/SignOutButton.vue';
import { login } from '@/routes';

defineProps<{ signedIn: boolean }>();
</script>

<template>
    <main class="flex min-h-svh flex-col items-center justify-center gap-4">
        <template v-if="signedIn">
            <p class="text-gray-900">You're signed in.</p>
            <SignOutButton />
        </template>
        <Link v-else :href="login()" class="text-gray-900 underline">Sign in</Link>
    </main>
</template>
