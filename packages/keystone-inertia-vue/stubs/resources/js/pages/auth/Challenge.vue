<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CredentialTypeForm from '@/components/CredentialTypeForm.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { cancel } from '@/routes/login/challenge';
import type { ChallengePage } from '@/types/auth';

defineOptions({ layout: AuthLayout });

const props = defineProps<ChallengePage>();

const selected = ref(props.preselect);

const option = computed(() => props.types.find((type) => type.type === selected.value) ?? props.types[0]);

const others = computed(() => props.types.filter((type) => type.type !== option.value?.type));
</script>

<template>
    <Head title="Confirm it's you" />

    <h1 class="text-xl font-semibold text-gray-900">Confirm it's you</h1>

    <CredentialTypeForm v-if="option" :key="option.type" :option="option" surface="challenge" />

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

    <Form v-bind="cancel.form()">
        <button type="submit" class="text-sm text-gray-600 underline">Cancel sign-in</button>
    </Form>
</template>
