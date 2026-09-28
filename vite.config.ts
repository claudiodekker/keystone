import vue from '@vitejs/plugin-vue';
import { configDefaults, defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    build: {
        manifest: true,
        outDir: 'workbench/public/build',
        emptyOutDir: true,
        rollupOptions: {
            input: 'workbench/resources/js/app.ts',
        },
    },
    test: {
        exclude: [...configDefaults.exclude, 'vendor/**'],
    },
});
