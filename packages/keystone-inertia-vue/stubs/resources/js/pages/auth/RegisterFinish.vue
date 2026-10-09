<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CredentialTypeForm from '@/components/CredentialTypeForm.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import type { RegisterFinishPage } from '@/types/auth';

defineOptions({ layout: AuthLayout });

defineProps<RegisterFinishPage>();
</script>

<template>
    <Head title="Finish creating your account" />

    <h1 class="text-xl font-semibold text-gray-900">Finish creating your account</h1>

    <p v-if="status" class="text-sm font-medium text-green-600">{{ status }}</p>

    <p class="text-sm text-gray-600">
        You confirmed <span class="font-medium text-gray-900">{{ address }}</span> is yours.
    </p>

    <CredentialTypeForm v-for="option in types" :key="option.type" :option="option" surface="registration" />
</template>
