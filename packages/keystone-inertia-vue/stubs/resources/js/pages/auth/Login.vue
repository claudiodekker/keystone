<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AuthLayout from '@/layouts/AuthLayout.vue'
import { componentFor } from '@/partials'
import type { SignInPage } from '@/types/auth'

defineOptions({ layout: AuthLayout })

defineProps<SignInPage>()
</script>

<template>
  <Head title="Sign in" />

  <h1 class="text-xl font-semibold text-gray-900">Sign in</h1>

  <p v-if="status" class="text-sm text-green-700">{{ status }}</p>

  <component :is="componentFor(type)" v-for="type in types" :key="type.name" :type="type" />
</template>
