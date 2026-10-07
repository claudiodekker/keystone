<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { submit } from '@/routes/login';
import { submit as submitChallenge } from '@/routes/login/challenge';
import { submit as submitEnrollment } from '@/routes/login/enrollment';
import { submit as submitSudo } from '@/routes/sudo';
import type { CredentialTypeOption, Purpose, SignInPage, Surface } from '@/types/auth';

const props = defineProps<{ option: CredentialTypeOption; surface: Surface; purpose?: Purpose }>();

defineSlots<{
    default(props: { errors: Partial<Record<string, string>>; processing: boolean }): unknown;
}>();

const page = usePage<Partial<SignInPage>>();

const routes = { 'sign-in': submit, challenge: submitChallenge, enrollment: submitEnrollment };

const action = computed(() => (props.purpose === 'sudo' ? submitSudo : routes[props.surface]).form({ type: props.option.type }));

const namesAccount = computed(() => props.surface === 'sign-in' && props.purpose !== 'sudo');

const secretFields = ['password', 'code'];

const shownByField = computed(() => secretFields.includes(props.option.type));

const labels = { 'sign-in': 'Sign in', challenge: 'Verify', enrollment: 'Set up' };

const label = computed(() => (props.purpose === 'sudo' ? 'Confirm' : labels[props.surface]));
</script>

<template>
    <Form v-bind="action" :reset-on-error="secretFields" v-slot="{ errors, processing }" class="flex flex-col gap-4">
        <div v-if="namesAccount" class="flex flex-col gap-2">
            <label :for="`${option.type}-identifier`" class="text-sm font-medium text-gray-900">Email address</label>
            <input
                :id="`${option.type}-identifier`"
                name="identifier"
                type="email"
                autocomplete="username"
                :defaultValue="page.props.identifier ?? ''"
                required
                class="rounded-md border border-gray-300 px-3 py-2 text-sm"
            />
            <p v-if="errors.identifier" class="text-sm text-red-600">{{ errors.identifier }}</p>
        </div>

        <slot :errors="errors" :processing="processing" />

        <label v-if="namesAccount && page.props.rememberOffered" class="flex items-center gap-2 text-sm text-gray-900">
            <input name="remember" type="checkbox" value="1" />
            Remember me
        </label>

        <p v-if="errors[option.type] && !shownByField" class="text-sm text-red-600">{{ errors[option.type] }}</p>

        <button type="submit" :disabled="processing" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
            {{ label }}
        </button>
    </Form>
</template>
