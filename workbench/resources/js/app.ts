import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';

const pages = {
    ...import.meta.glob<DefineComponent>('@/pages/**/*.vue', { import: 'default' }),
    ...import.meta.glob<DefineComponent>('./pages/**/*.vue', { import: 'default' }),
};

void createInertiaApp({
    resolve: (name) => {
        const page = Object.entries(pages).find(([path]) => path.endsWith(`/pages/${name}.vue`));

        if (!page) {
            throw new Error(`Page not found: ${name}`);
        }

        return page[1]();
    },
});
