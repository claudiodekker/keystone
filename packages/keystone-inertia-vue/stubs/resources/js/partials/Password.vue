<script setup lang="ts">
import PasswordField from '@/components/PasswordField.vue';
import FormShape from '@/partials/shapes/Form.vue';
import type { CredentialTypeOption, Purpose, Surface } from '@/types/auth';

defineProps<{ option: CredentialTypeOption; surface: Surface; purpose?: Purpose }>();
</script>

<template>
    <FormShape :option="option" :surface="surface" :purpose="purpose" v-slot="{ errors }">
        <PasswordField :id="`${option.type}-password`" :error="errors.password" />
    </FormShape>
</template>
