<script setup lang="ts">
import FormShape from '@/partials/shapes/Form.vue';
import type { CredentialTypeOption, Purpose, Surface } from '@/types/auth';

defineProps<{ option: CredentialTypeOption; surface: Surface; purpose?: Purpose }>();
</script>

<template>
    <FormShape :option="option" :surface="surface" :purpose="purpose" v-slot="{ errors }">
        <div class="flex flex-col gap-2">
            <label :for="`${option.type}-code`" class="text-sm font-medium text-gray-900">Recovery code</label>
            <input
                :id="`${option.type}-code`"
                name="code"
                type="text"
                autocomplete="off"
                autocapitalize="characters"
                spellcheck="false"
                required
                autofocus
                class="rounded-md border border-gray-300 px-3 py-2 font-mono text-sm"
            />
            <p v-if="errors.code" class="text-sm text-red-600">{{ errors.code }}</p>
        </div>
    </FormShape>
</template>
