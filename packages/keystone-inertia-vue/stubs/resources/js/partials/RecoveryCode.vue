<template>
  <Form v-slot="{ errors }" :action="action ?? submit(type.name)" disable-while-processing class="flex flex-col gap-6">
    <div class="grid gap-2">
      <label :for="id" class="text-sm font-medium text-gray-700">Recovery code</label>
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
</template>

<script setup lang="ts">
import { Form } from '@inertiajs/vue3'
import { useId } from 'vue'
import InputError from '@/components/InputError.vue'
import { submit } from '@/routes/login/challenge'
import type { ChallengeType } from '@/types/auth'
import type { RouteDefinition } from '@/wayfinder'

defineProps<{
  /** The recovery code type as the challenge offers it. */
  type: ChallengeType
  /** Where the form posts, the challenge's answer when not given. */
  action?: RouteDefinition<'post'>
}>()

const id = useId()
</script>
