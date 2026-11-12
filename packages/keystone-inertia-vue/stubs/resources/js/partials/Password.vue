<script setup lang="ts">
import InputError from '@/components/InputError.vue'
import FormShape from '@/partials/shapes/Form.vue'
import type { SignInType } from '@/types/auth'
import type { RouteDefinition } from '@/wayfinder'

defineProps<{
  /** The password type as the page offers it. */
  type: SignInType
  /** Whether the form offers the remember-me box. */
  remember?: boolean
  /** Where the form posts, the password's sign-in when not given. */
  action?: RouteDefinition<'post'>
  /** Whether a signed-in user is confirming it is them, so the form asks for no email address. */
  confirming?: boolean
}>()
</script>

<template>
  <FormShape :type="type" :remember="remember" :action="action" :confirming="confirming">
    <template #default="{ errors }">
      <div class="grid gap-2">
        <div class="flex items-center justify-between">
          <label :for="`${type.name}-password`" class="text-sm font-medium text-gray-700">Password</label>
          <a href="#" class="text-sm text-gray-600 underline">Lost access?</a>
        </div>
        <input
          :id="`${type.name}-password`"
          name="password"
          type="password"
          autocomplete="current-password"
          required
          class="rounded-md border border-gray-300 px-3 py-2 text-sm"
        />
        <InputError :message="errors.password" />
      </div>
    </template>
  </FormShape>
</template>
