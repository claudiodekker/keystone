import { wayfinder } from '@laravel/vite-plugin-wayfinder'
import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import laravel from 'laravel-vite-plugin'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vite'

const stubs = fileURLToPath(new URL('../packages/keystone-inertia-vue/stubs/resources/js', import.meta.url))
const generated = fileURLToPath(new URL('./resources/js', import.meta.url))

export default defineConfig({
  resolve: {
    alias: [
      { find: /^@\/(routes|actions|wayfinder)/, replacement: `${generated}/$1` },
      { find: '@', replacement: stubs },
    ],
  },
  plugins: [
    laravel({
      input: ['workbench/resources/css/app.css', 'packages/keystone-inertia-vue/stubs/resources/js/app.ts'],
      publicDirectory: 'workbench/public',
    }),
    tailwindcss(),
    vue(),
    wayfinder({
      command: 'php vendor/bin/testbench wayfinder:generate',
      path: 'workbench/resources/js',
      actions: false,
    }),
  ],
})
