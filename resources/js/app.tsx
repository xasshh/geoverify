import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';
import './bootstrap';
import { registerServiceWorker } from '@/lib/pwa';

const appName = 'GeoVerify';

void createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    /**
     * Pages are loaded on demand, not all at once.
     *
     * Resolving them eagerly put every screen in one bundle, which meant a
     * field officer downloaded and parsed MapLibre and the whole supervisor
     * console before they could see their assignment list. The download is a
     * one time cost on wifi because the service worker precaches it, but the
     * parse is paid on every cold start, on the cheapest handset in the
     * programme.
     *
     * Splitting is safe offline precisely because of that precache: the
     * worker's globPatterns take every emitted chunk, so a page loaded on
     * demand is still a page already on the device.
     */
    resolve: (name) =>
        resolvePageComponent<{ default: ComponentType }>(
            `./pages/${name}.tsx`,
            import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        // The verification accent. Sync state is never a mystery.
        color: '#0E7C72',
    },
});

// Installable and offline capable. The update waits for the officer rather than
// reloading the app while they are part way through a building.
registerServiceWorker(() => {
    window.dispatchEvent(new CustomEvent('geoverify:update-ready'));
});
