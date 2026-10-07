<script setup lang="ts">
import FormShape from '@/partials/shapes/Form.vue';
import type { CredentialTypeOption, Purpose, Surface } from '@/types/auth';

defineProps<{ option: CredentialTypeOption; surface: Surface; purpose?: Purpose }>();
</script>

<template>
    <FormShape :option="option" :surface="surface" :purpose="purpose" v-slot="{ errors }">
        <div v-if="surface === 'enrollment' && option.ceremony" class="flex flex-col gap-2">
            <p class="text-sm text-gray-600">Add this key to your authenticator app, or open the link on the device it runs on:</p>
            <code class="rounded-md bg-gray-100 px-3 py-2 font-mono text-sm break-all text-gray-900">{{ option.ceremony.key }}</code>
            <a :href="option.ceremony.uri" class="text-sm font-medium text-gray-900 underline">Open in authenticator app</a>
        </div>

        <div class="flex flex-col gap-2">
            <label :for="`${option.type}-code`" class="text-sm font-medium text-gray-900">Code from your authenticator app</label>
            <input
                :id="`${option.type}-code`"
                name="code"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                required
                autofocus
                class="rounded-md border border-gray-300 px-3 py-2 text-sm tracking-widest"
            />
            <p v-if="errors.code" class="text-sm text-red-600">{{ errors.code }}</p>
        </div>
    </FormShape>
</template>
