import { setWorkerUrl } from 'maplibre-gl';
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';

/**
 * Points MapLibre at its own worker.
 *
 * MapLibre builds the worker URL at runtime, which a bundler cannot statically
 * analyse, so Vite never emits the file and the worker 404s. Nothing errors when
 * that happens: the map initialises, the background paints, the controls appear,
 * and no source ever finishes loading. Importing the worker with ?url makes Vite
 * emit it and hands back the hashed path.
 *
 * ?worker&url rather than plain ?url: the worker imports maplibre-gl-shared.mjs,
 * and a plain ?url copies the file verbatim without resolving that import, so the
 * worker then 404s on its own dependency. ?worker&url bundles the worker with its
 * imports and returns the URL of the bundle.
 *
 * Import this module once before constructing a Map.
 */
setWorkerUrl(workerUrl);

export { workerUrl };
