<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CredentialTypeForm from '@/components/CredentialTypeForm.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import type { SudoPage } from '@/types/auth';

defineOptions({ layout: AuthLayout });

const props = defineProps<SudoPage>();

const selected = ref(props.preselect);

const option = computed(() => props.types.find((type) => type.type === selected.value) ?? props.types[0]);

const others = computed(() => props.types.filter((type) => type.type !== option.value?.type));
</script>

<template>
    <Head title="Confirm it's you" />

    <h1 class="text-xl font-semibold text-gray-900">Confirm it's you</h1>

    <p class="text-sm text-gray-600">This change needs you to prove it's you again, the way you sign in.</p>

    <CredentialTypeForm v-if="option" :key="option.type" :option="option" :surface="surface" purpose="sudo" />

    <p v-else class="text-sm text-gray-600">Your account holds nothing you can confirm it's you with. Sign out and sign in again to continue.</p>

    <div v-if="others.length" class="flex flex-col gap-2">
        <p class="text-sm text-gray-600">Use something else:</p>
        <button
            v-for="other in others"
            :key="other.type"
            type="button"
            class="text-left text-sm font-medium text-gray-900 underline"
            @click="selected = other.type"
        >
            {{ other.type }}
        </button>
    </div>
</template>
