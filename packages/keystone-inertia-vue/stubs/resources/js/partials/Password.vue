<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import PasswordField from '@/components/PasswordField.vue';
import FormShape from '@/partials/shapes/Form.vue';
import { remove } from '@/routes/security/credentials';
import type { CredentialTypeOption, Purpose, Surface } from '@/types/auth';

const props = defineProps<{ option: CredentialTypeOption; surface: Surface; purpose?: Purpose }>();

const held = computed(() => props.option.held?.[0] ?? null);
</script>

<template>
    <div v-if="purpose === 'settings'" class="flex flex-col gap-4">
        <p v-if="!held" class="text-sm text-gray-600">Your account does not have a password set.</p>
        <p v-else class="text-sm text-gray-600">Changing your password signs out your other sessions.</p>

        <FormShape
            :option="option"
            :surface="surface"
            :purpose="purpose"
            :fields="['current_password', 'password', 'password_confirmation']"
            :submit-label="held ? 'Change password' : undefined"
            v-slot="{ errors }"
        >
            <PasswordField
                v-if="held"
                :id="`${option.type}-current-password`"
                name="current_password"
                autocomplete="current-password"
                label="Current password"
                :error="errors.current_password"
            />
            <p v-else-if="errors.current_password" class="text-sm text-red-600">{{ errors.current_password }}</p>
            <PasswordField :id="`${option.type}-password`" autocomplete="new-password" label="New password" :error="errors.password" />
            <PasswordField
                :id="`${option.type}-password-confirmation`"
                name="password_confirmation"
                autocomplete="new-password"
                label="Confirm new password"
                :error="errors.password_confirmation"
            />
        </FormShape>

        <Link v-if="held?.removable" :href="remove(held.id)" class="text-sm font-medium text-gray-900 underline">Remove password</Link>
    </div>

    <FormShape v-else :option="option" :surface="surface" :purpose="purpose" :fields="['password']" v-slot="{ errors }">
        <PasswordField :id="`${option.type}-password`" :error="errors.password" />
    </FormShape>
</template>
