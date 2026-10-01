<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed } from 'vue';
import { submit } from '@/routes/login';
import { submit as submitChallenge } from '@/routes/login/challenge';
import { submit as submitEnrollment } from '@/routes/login/enrollment';
import type { CredentialTypeOption, Surface } from '@/types/auth';

const props = defineProps<{ option: CredentialTypeOption; surface: Surface }>();

defineSlots<{
    default(props: { errors: Partial<Record<string, string>>; processing: boolean }): unknown;
}>();

const routes = { 'sign-in': submit, challenge: submitChallenge, enrollment: submitEnrollment };

const action = computed(() => routes[props.surface].form({ type: props.option.type }));

const labels = { 'sign-in': 'Sign in', challenge: 'Verify', enrollment: 'Set up' };
</script>

<template>
    <Form v-bind="action" v-slot="{ errors, processing }" class="flex flex-col gap-4">
        <div v-if="surface === 'sign-in'" class="flex flex-col gap-2">
            <label :for="`${option.type}-identifier`" class="text-sm font-medium text-gray-900">Email address</label>
            <input
                :id="`${option.type}-identifier`"
                name="identifier"
                type="email"
                autocomplete="username"
                required
                class="rounded-md border border-gray-300 px-3 py-2 text-sm"
            />
            <p v-if="errors.identifier" class="text-sm text-red-600">{{ errors.identifier }}</p>
        </div>

        <slot :errors="errors" :processing="processing" />

        <p v-if="errors[option.type]" class="text-sm text-red-600">{{ errors[option.type] }}</p>

        <button type="submit" :disabled="processing" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
            {{ labels[surface] }}
        </button>
    </Form>
</template>
