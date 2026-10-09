<script setup lang="ts">
withDefaults(defineProps<{ id: string; error?: string; name?: string; autocomplete?: string; label?: string }>(), {
    name: 'password',
    autocomplete: 'current-password',
    label: 'Password',
});
</script>

<template>
    <div class="flex flex-col gap-2">
        <label :for="id" class="text-sm font-medium text-gray-900">{{ label }}</label>
        <input
            :id="id"
            :name="name"
            type="password"
            :autocomplete="autocomplete"
            required
            class="rounded-md border border-gray-300 px-3 py-2 text-sm"
        />
        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
    </div>
</template>
