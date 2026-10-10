<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import CredentialTypeForm from '@/components/CredentialTypeForm.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { enrollment } from '@/routes/login';
import { cancel } from '@/routes/login/enrollment';
import type { CredentialTypeOption, EnrollmentFormPage } from '@/types/auth';

defineOptions({ layout: AuthLayout });

const props = defineProps<EnrollmentFormPage>();

const option = computed<CredentialTypeOption>(() => ({ type: props.type, shape: props.shape, ceremony: props.ceremony }));
</script>

<template>
    <Head title="Set up two-factor authentication" />

    <h1 class="text-xl font-semibold text-gray-900">Set up two-factor authentication</h1>

    <p v-if="status" class="text-sm font-medium text-red-600">{{ status }}</p>

    <CredentialTypeForm :key="option.type" :option="option" surface="enrollment" />

    <Link :href="enrollment()" class="text-sm text-gray-600 underline">Choose another method</Link>

    <Form v-bind="cancel.form()">
        <button type="submit" class="text-sm text-gray-600 underline">{{ origin === 'registration' ? 'Sign out' : 'Cancel sign-in' }}</button>
    </Form>
</template>
