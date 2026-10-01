<script setup lang="ts">
import PasswordField from '@/components/PasswordField.vue';
import FormShape from '@/partials/shapes/Form.vue';
import type { CredentialTypeOption, Surface } from '@/types/auth';

defineProps<{ option: CredentialTypeOption; surface: Surface }>();
</script>

<template>
    <FormShape :option="option" :surface="surface" v-slot="{ errors }">
        <PasswordField :id="`${option.type}-password`" :error="errors.password" />
    </FormShape>
</template>
