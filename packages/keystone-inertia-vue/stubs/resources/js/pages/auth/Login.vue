<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CredentialTypeForm from '@/components/CredentialTypeForm.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import type { SignInPage } from '@/types/auth';

defineOptions({ layout: AuthLayout });

defineProps<SignInPage>();
</script>

<template>
    <Head title="Sign in" />

    <h1 class="text-xl font-semibold text-gray-900">Sign in</h1>

    <p v-if="status" class="text-sm font-medium text-green-600">{{ status }}</p>

    <CredentialTypeForm v-for="option in types" :key="option.type" :option="option" />
</template>
