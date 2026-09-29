import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath } from 'node:url';
import { configDefaults, defineConfig } from 'vitest/config';

const stubs = 'packages/keystone-inertia-vue/stubs/resources/js';

export default defineConfig({
    plugins: [
        !process.env.VITEST &&
            laravel({
                input: ['workbench/resources/css/app.css', 'workbench/resources/js/app.ts'],
                publicDirectory: 'workbench/public',
                hotFile: 'vendor/orchestra/testbench-core/laravel/public/hot',
            }),
        tailwindcss(),
        vue(),
        !process.env.VITEST &&
            wayfinder({
                command: 'php vendor/bin/testbench wayfinder:generate',
                path: stubs,
                formVariants: true,
                patterns: ['packages/*/stubs/routes/**/*.php', 'packages/*/stubs/app/**/Http/**/*.php', 'workbench/routes/**/*.php'],
            }),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL(stubs, import.meta.url)),
        },
    },
    test: {
        exclude: [...configDefaults.exclude, 'vendor/**'],
    },
});
