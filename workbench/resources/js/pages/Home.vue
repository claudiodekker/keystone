<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import SignOutButton from '@/components/SignOutButton.vue';
import { login } from '@/routes';
import { security } from '@/routes/settings';
import { end } from '@/routes/sudo';

defineProps<{ signedIn: boolean; status: string | null }>();
</script>

<template>
    <main class="flex min-h-svh flex-col items-center justify-center gap-4">
        <p v-if="status" class="text-sm font-medium text-green-600">{{ status }}</p>
        <template v-if="signedIn">
            <p class="text-gray-900">You're signed in.</p>
            <Link :href="security()" class="text-gray-900 underline">Security settings</Link>
            <Form v-bind="end.form()">
                <button type="submit" class="text-sm font-medium text-gray-900 underline">End sudo</button>
            </Form>
            <SignOutButton />
        </template>
        <Link v-else :href="login()" class="text-gray-900 underline">Sign in</Link>
    </main>
</template>
