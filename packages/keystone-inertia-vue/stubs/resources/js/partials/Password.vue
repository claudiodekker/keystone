<script setup lang="ts">
import PasswordField from '@/components/PasswordField.vue';
import FormShape from '@/partials/shapes/Form.vue';
import type { CredentialTypeOption } from '@/types/auth';

defineProps<{ option: CredentialTypeOption }>();
</script>

<template>
    <FormShape :option="option" v-slot="{ errors }">
        <PasswordField :id="`${option.type}-password`" :error="errors.password" />
    </FormShape>
</template>
