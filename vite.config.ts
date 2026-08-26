import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';
import { copyFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),

        /**
         * The field client is installable and works with the radio off.
         *
         * The offline data layer lands at M5. What this does now is make the app
         * itself survive having no signal: the shell, the fonts and the compiled
         * assets are precached, so an officer who opens the app in a dead spot
         * gets the interface rather than a browser error page.
         */
        {
            /*
             * The web app manifest, at the URL the service worker precaches.
             *
             * vite-plugin-pwa emits it into the Vite build directory with every
             * other asset, but injects it into the precache list at the web
             * root, after the prefix rewrite that fixes up everything else. It
             * therefore 404s, and one missing precache entry fails the whole
             * install: the worker goes redundant and the app silently stops
             * being offline capable, which for a field client is the entire
             * point of it. Copying the file to the root satisfies the worker
             * without moving what the document already links to.
             */
            name: 'geoverify-webmanifest-at-root',
            apply: 'build',
            closeBundle() {
                const built = resolve(__dirname, 'public/build/manifest.webmanifest');
                const root = resolve(__dirname, 'public/manifest.webmanifest');

                if (existsSync(built)) {
                    copyFileSync(built, root);
                }
            },
        },

        VitePWA({
            registerType: 'prompt',
            injectRegister: null,

            // The worker is written to the web root, not into build/. A service
            // worker's scope is capped by the directory it is served from, so one
            // at /build/sw.js can only ever control /build/ and would never see a
            // navigation to /field.
            outDir: 'public',
            filename: 'sw.js',
            manifestFilename: 'manifest.webmanifest',
            includeAssets: [],

            manifest: {
                name: 'GeoVerify Field',
                short_name: 'GeoVerify',
                description: 'GPS verified business enumeration for field officers.',
                // Opens straight into the officer's work, not a marketing page.
                start_url: '/field',
                scope: '/',
                display: 'standalone',
                orientation: 'portrait',
                // Dusk, because an installed field app opens to the dark surface
                // and a white flash at launch is what a cheap app looks like.
                background_color: '#0E1E2E',
                theme_color: '#0E1E2E',
                icons: [
                    { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
                    { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
                    {
                        src: '/icons/icon-maskable-512.png',
                        sizes: '512x512',
                        type: 'image/png',
                        purpose: 'maskable',
                    },
                ],
            },

            workbox: {
                // The compiled assets live under public/build even though the
                // worker itself does not, so the glob and the URL prefix are
                // pointed there explicitly.
                globDirectory: 'public/build',
                modifyURLPrefix: { '': '/build/' },


                // Fonts are large and never change within a release. Precaching
                // them is what keeps type from blocking on a 2G connection.
                globPatterns: ['**/*.{js,css,woff2,png,svg,ico}'],
                maximumFileSizeToCacheInBytes: 6 * 1024 * 1024,
                navigateFallback: null,
                cleanupOutdatedCaches: true,

                runtimeCaching: [
                    {
                        // Map tiles and boundary geometry: expensive to fetch, and
                        // unchanged for the life of a mandate.
                        urlPattern: /\/console\/coverage\/\d+\/(cells|boundary)\.geojson/,
                        handler: 'StaleWhileRevalidate',
                        options: {
                            cacheName: 'geoverify-geometry',
                            expiration: { maxEntries: 40, maxAgeSeconds: 60 * 60 * 24 * 30 },
                        },
                    },
                    {
                        // The sector picker. Cached so it still works with no
                        // signal, which is when most capture happens.
                        urlPattern: /\/api\/field\/sectors/,
                        handler: 'StaleWhileRevalidate',
                        options: {
                            cacheName: 'geoverify-sectors',
                            expiration: { maxEntries: 200, maxAgeSeconds: 60 * 60 * 24 * 7 },
                        },
                    },
                    {
                        // The map pack is tens of megabytes and is stored in
                        // IndexedDB by the app itself. Letting the worker cache it
                        // as well would keep two copies of 67 MB on a handset that
                        // has neither to spare.
                        urlPattern: /\/api\/field\/packs\/\d+/,
                        handler: 'NetworkOnly',
                    },
                ],
            },

            devOptions: { enabled: false },
        }),
    ],
    resolve: {
        alias: {
            '@': resolve(import.meta.dirname, 'resources/js'),
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
