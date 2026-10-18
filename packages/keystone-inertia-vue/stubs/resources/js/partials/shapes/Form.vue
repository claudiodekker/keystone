<script setup lang="ts">
import { Form } from '@inertiajs/vue3'
import InputError from '@/components/InputError.vue'
import { submit } from '@/routes/login'
import type { SignInType } from '@/types/auth'

defineProps<{
  type: SignInType
}>()
</script>

<template>
  <Form v-slot="{ errors, processing }" :action="submit(type.name)" class="flex flex-col gap-6">
    <div class="grid gap-2">
      <label :for="`${type.name}-identifier`" class="text-sm font-medium text-gray-700">Email address</label>
      <input
        :id="`${type.name}-identifier`"
        name="identifier"
        type="text"
        autocomplete="username"
        required
        class="rounded-md border border-gray-300 px-3 py-2 text-sm"
      />
      <InputError :message="errors.identifier" />
    </div>

    <slot :errors="errors" />

    <button
      type="submit"
      :disabled="processing"
      class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-50"
    >
      Sign in
    </button>
  </Form>
</template>
