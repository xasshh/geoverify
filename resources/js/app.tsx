import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';
import './bootstrap';
import { registerServiceWorker } from '@/lib/pwa';

const appName = 'GeoVerify';

void createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx', {
            eager: true,
        });
        const page = pages[`./pages/${name}.tsx`];

        if (!page) {
            throw new Error(`Inertia page not found: ${name}`);
        }

        return page;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        // Gold, matching the verification accent. Sync state is never a mystery.
        color: '#D0AE63',
    },
});

// Installable and offline capable. The update waits for the officer rather than
// reloading the app while they are part way through a building.
registerServiceWorker(() => {
    window.dispatchEvent(new CustomEvent('geoverify:update-ready'));
});
