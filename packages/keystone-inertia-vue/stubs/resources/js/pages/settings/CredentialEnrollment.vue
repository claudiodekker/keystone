<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import CredentialTypeForm from '@/components/CredentialTypeForm.vue';
import { typeName } from '@/lib/credentialTypes';
import { cancel } from '@/routes/security/enroll';
import type { CredentialTypeOption, EnrollmentFormPage } from '@/types/auth';

const props = defineProps<EnrollmentFormPage>();

const option = computed<CredentialTypeOption>(() => ({ type: props.type, shape: props.shape, ceremony: props.ceremony, held: props.held }));

const heading = computed(() => (props.type === 'password' && props.held.length ? 'Change password' : `Set up ${typeName(props.type)}`));
</script>

<template>
    <Head :title="heading" />

    <div class="flex min-h-svh justify-center bg-gray-50 p-6">
        <main class="flex w-full max-w-md flex-col gap-6 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
            <h1 class="text-xl font-semibold text-gray-900">{{ heading }}</h1>

            <p v-if="status" class="text-sm font-medium text-red-600">{{ status }}</p>

            <CredentialTypeForm :key="option.type" :option="option" surface="enrollment" purpose="settings" />

            <Form v-bind="cancel.form({ type })">
                <button type="submit" class="text-sm text-gray-600 underline">Cancel</button>
            </Form>
        </main>
    </div>
</template>
