<template>
  <Head title="Confirm it's you" />

  <h1 class="text-xl font-semibold text-gray-900">Confirm it's you</h1>

  <div v-if="types.length > 1" class="flex gap-2">
    <button
      v-for="type in types"
      :key="type.name"
      type="button"
      :aria-pressed="type.name === selected?.name"
      class="rounded-md border border-gray-300 px-3 py-1 text-sm aria-pressed:bg-gray-900 aria-pressed:text-white"
      @click="selectedName = type.name"
    >
      {{ type.name }}
    </button>
  </div>

  <component :is="componentFor(selected)" v-if="selected" :key="selected.name" :type="selected" />

  <Form :action="cancel()" disable-while-processing class="flex justify-center">
    <button type="submit" class="text-sm text-gray-600 underline">Cancel and sign in again</button>
  </Form>
</template>

<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AuthLayout from '@/layouts/AuthLayout.vue'
import { componentFor } from '@/partials'
import { cancel } from '@/routes/login/challenge'
import type { ChallengePage } from '@/types/auth'

defineOptions({ layout: AuthLayout })

const props = defineProps<ChallengePage>()

const selectedName = ref(props.preselected)

const selected = computed(() => props.types.find((type) => type.name === selectedName.value))
</script>
