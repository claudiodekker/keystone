import type { SignInType } from '@/types/auth'
import type { Component } from 'vue'

const partials = import.meta.glob<Component>('./*.vue', { eager: true, import: 'default' })
const shapes = import.meta.glob<Component>('./shapes/*.vue', { eager: true, import: 'default' })

const studly = (value: string) =>
  value
    .split(/[-_\s]+/)
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join('')

export function componentFor(type: SignInType): Component | undefined {
  return partials[`./${studly(type.name)}.vue`] ?? shapes[`./shapes/${studly(type.shape)}.vue`]
}
