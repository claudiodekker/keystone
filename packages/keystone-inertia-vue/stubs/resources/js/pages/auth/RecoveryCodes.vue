<template>
  <Head title="Save your recovery codes" />

  <h1 class="text-xl font-semibold text-gray-900">Save your recovery codes</h1>

  <p class="text-sm text-gray-700">
    Each code signs you in once if you lose your authenticator. Keep them somewhere safe, then type one back to finish.
  </p>

  <ul class="grid grid-cols-2 gap-2 rounded-md bg-gray-100 p-3 font-mono text-sm tracking-wider">
    <li v-for="code in codes" :key="code">{{ code }}</li>
  </ul>

  <Form v-slot="{ errors }" :action="submit('recovery-code')" disable-while-processing class="flex flex-col gap-6">
    <div class="grid gap-2">
      <label :for="id" class="text-sm font-medium text-gray-700">One of the codes above</label>
      <input
        :id="id"
        name="recovery_code"
        type="text"
        autocomplete="off"
        autocapitalize="characters"
        spellcheck="false"
        required
        autofocus
        class="rounded-md border border-gray-300 px-3 py-2 font-mono text-sm tracking-wider"
      />
      <InputError :message="errors.recovery_code" />
    </div>

    <button
      type="submit"
      class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-50"
    >
      Continue
    </button>
  </Form>

  <Form :action="cancel()" disable-while-processing class="flex justify-center">
    <button type="submit" class="text-sm text-gray-600 underline">Cancel and sign in again</button>
  </Form>
</template>

<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3'
import { useId } from 'vue'
import InputError from '@/components/InputError.vue'
import AuthLayout from '@/layouts/AuthLayout.vue'
import { cancel, submit } from '@/routes/login/enrollment'
import type { RecoveryCodesPage } from '@/types/auth'

defineOptions({ layout: AuthLayout })

defineProps<RecoveryCodesPage>()

const id = useId()
</script>
