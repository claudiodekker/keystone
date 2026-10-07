<script setup lang="ts">
import { computed, type Component } from 'vue';
import type { CredentialTypeOption, Purpose, Surface } from '@/types/auth';

const props = defineProps<{ option: CredentialTypeOption; surface: Surface; purpose?: Purpose }>();

const partials = import.meta.glob<Component>('@/partials/*.vue', { eager: true, import: 'default' });
const shapes = import.meta.glob<Component>('@/partials/shapes/*.vue', { eager: true, import: 'default' });

const studly = (name: string): string => name.replace(/(?:^|[-_])(\w)/g, (_match, letter: string) => letter.toUpperCase());

const named = (components: Record<string, Component>, name: string): Component | undefined =>
    Object.entries(components).find(([path]) => path.endsWith(`/${studly(name)}.vue`))?.[1];

const component = computed(() => named(partials, props.option.type) ?? named(shapes, props.option.shape));
</script>

<template>
    <component :is="component" v-if="component" :option="option" :surface="surface" :purpose="purpose" />
</template>
