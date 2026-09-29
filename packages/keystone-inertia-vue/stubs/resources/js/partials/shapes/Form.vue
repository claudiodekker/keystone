<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { submit } from '@/routes/login';
import type { CredentialTypeOption } from '@/types/auth';

defineProps<{ option: CredentialTypeOption }>();

defineSlots<{
    default(props: { errors: Partial<Record<string, string>>; processing: boolean }): unknown;
}>();
</script>

<template>
    <Form v-bind="submit.form({ type: option.type })" v-slot="{ errors, processing }" class="flex flex-col gap-4">
        <div class="flex flex-col gap-2">
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

        <button type="submit" :disabled="processing" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
            Sign in
        </button>
    </Form>
</template>
