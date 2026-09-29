<script setup lang="ts">
import FormShape from '@/components/keystone/shapes/Form.vue';
import type { CredentialTypeOption } from '@/types/keystone';

defineProps<{ option: CredentialTypeOption }>();
</script>

<template>
    <FormShape :option="option" v-slot="{ errors }">
        <div class="flex flex-col gap-2">
            <div class="flex items-center justify-between">
                <label :for="`${option.type}-password`" class="text-sm font-medium text-gray-900">Password</label>
                <a href="#" class="text-sm text-gray-600 underline">Lost access?</a>
            </div>
            <input
                :id="`${option.type}-password`"
                name="password"
                type="password"
                autocomplete="current-password"
                required
                class="rounded-md border border-gray-300 px-3 py-2 text-sm"
            />
            <p v-if="errors.password" class="text-sm text-red-600">{{ errors.password }}</p>
        </div>
    </FormShape>
</template>
