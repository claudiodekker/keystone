<script setup lang="ts">
defineProps<{ id: string; error?: string }>();
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex items-center justify-between">
            <label :for="id" class="text-sm font-medium text-gray-900">Password</label>
            <a href="#" class="text-sm text-gray-600 underline">Lost access?</a>
        </div>
        <input
            :id="id"
            name="password"
            type="password"
            autocomplete="current-password"
            required
            class="rounded-md border border-gray-300 px-3 py-2 text-sm"
        />
        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
    </div>
</template>
