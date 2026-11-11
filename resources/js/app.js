import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';

import { applyStoredTheme } from '@/support/theme';

// Applied before the first paint so a dark mode user never sees a white flash.
applyStoredTheme();

const appName = import.meta.env.VITE_APP_NAME || 'Unboundbyte Solutions';

createInertiaApp({
    // The site name is added once. A title that already carries it (the home
    // page, legal pages, pages written in the panel) is left alone; until
    // November 2026 those showed the name twice in every search result.
    title: (title) => {
        if (!title) return appName;
        return title.includes(appName) ? title : `${title} — ${appName}`;
    },
    resolve: (name) =>
        resolvePageComponent(`./pages/${name}.vue`, import.meta.glob('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#06B6D4',
        showSpinner: false,
    },
});
