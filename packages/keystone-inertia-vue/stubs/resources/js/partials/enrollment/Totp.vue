<template>
  <div class="grid gap-2 text-sm text-gray-700">
    <p>Add this key to your authenticator app, then enter the code it shows.</p>
    <a :href="type.ceremony?.uri" class="underline">Open in your authenticator app</a>
    <code class="rounded-md bg-gray-100 px-3 py-2 font-mono tracking-wider break-all">{{ type.ceremony?.key }}</code>
  </div>

  <Form v-slot="{ errors }" :action="submit(type.name)" disable-while-processing class="flex flex-col gap-6">
    <div class="grid gap-2">
      <label :for="id" class="text-sm font-medium text-gray-700">Code from your authenticator app</label>
      <input
        :id="id"
        name="code"
        type="text"
        inputmode="numeric"
        pattern="[0-9]{6}"
        maxlength="6"
        autocomplete="one-time-code"
        required
        autofocus
        class="rounded-md border border-gray-300 px-3 py-2 text-sm tracking-widest"
      />
      <InputError :message="errors.code" />
    </div>

    <button
      type="submit"
      class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-50"
    >
      Continue
    </button>
  </Form>
</template>

<script setup lang="ts">
import { Form } from '@inertiajs/vue3'
import { useId } from 'vue'
import InputError from '@/components/InputError.vue'
import { submit } from '@/routes/login/enrollment'
import type { EnrollmentType } from '@/types/auth'

defineProps<{
  /** The TOTP type as enrollment offers it, with the key its ceremony set up. */
  type: EnrollmentType
}>()

const id = useId()
</script>
