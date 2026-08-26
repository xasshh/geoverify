/**
 * Registers the service worker, and tells the officer when a new version is
 * waiting rather than swapping it underneath them mid capture.
 *
 * registerType is 'prompt' deliberately: reloading an officer's app while they
 * are part way through a building would lose what is on screen. The update waits
 * until they say so.
 */
export function registerServiceWorker(onUpdateReady: () => void): void {
    if (!('serviceWorker' in navigator) || import.meta.env.DEV) {
        return;
    }

    // Registered on load so it never competes with the first paint, but only
    // if load is still coming. A module script routinely executes after the
    // load event has already fired, and a listener attached then never runs:
    // the worker silently never registers and the officer's app is not
    // offline capable at all, which is the one thing it has to be.
    const register = () => {
        void navigator.serviceWorker
            .register('/sw.js', { scope: '/' })
            .then((registration) => {
                registration.addEventListener('updatefound', () => {
                    const installing = registration.installing;

                    if (installing === null) {
                        return;
                    }

                    installing.addEventListener('statechange', () => {
                        if (installing.state === 'installed' && navigator.serviceWorker.controller !== null) {
                            onUpdateReady();
                        }
                    });
                });
            })
            .catch(() => {
                // An officer cannot act on a failed registration, and the app
                // works without it, so this stays silent rather than alarming.
            });
    };

    if (document.readyState === 'complete') {
        register();

        return;
    }

    window.addEventListener('load', register, { once: true });
}
