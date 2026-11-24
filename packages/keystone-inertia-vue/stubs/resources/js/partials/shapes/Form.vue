<script setup lang="ts">
import { Form } from '@inertiajs/vue3'
import InputError from '@/components/InputError.vue'
import { submit } from '@/routes/login'
import type { SignInType } from '@/types/auth'
import type { RouteDefinition } from '@/wayfinder'

defineProps<{
  /** The type as the page offers it. */
  type: SignInType
  /** Whether the form offers the remember-me box. */
  remember?: boolean
  /** Where the form posts, the type's sign-in when not given. */
  action?: RouteDefinition<'post'>
  /** Whether a signed-in user is confirming it is them, so the form asks for no email address. */
  confirming?: boolean
}>()
</script>

<template>
  <Form v-slot="{ errors, processing }" :action="action ?? submit(type.name)" class="flex flex-col gap-6">
    <div v-if="!confirming" class="grid gap-2">
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

    <label v-if="remember" :for="`${type.name}-remember`" class="flex items-center gap-2 text-sm text-gray-700">
      <input :id="`${type.name}-remember`" name="remember" type="checkbox" value="1" class="rounded border-gray-300" />
      Remember me
    </label>

    <button
      type="submit"
      :disabled="processing"
      class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-50"
    >
      {{ confirming ? 'Confirm' : 'Sign in' }}
    </button>
  </Form>
</template>
