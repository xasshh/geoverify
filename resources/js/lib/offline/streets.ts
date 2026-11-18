import { PMTiles } from 'pmtiles';
import { db, type LocalStreets } from './db';
import { DeviceSource } from './pack';

/**
 * The street map, carried offline beside the map pack.
 *
 * The same discipline as imagery.ts and pack.ts, in its own module and tables
 * so the code an officer already depends on is not touched: resumed by Range a
 * chunk at a time, assembled only when every byte is here, and a changed
 * checksum means a newer cut, never a continuation of the old one.
 *
 * The street names need fonts and the landmarks need icons. Both are small and
 * fixed, and are put in the browser cache the worker answers them from
 * (`geoverify-basemap-assets`), so the names still show with no signal.
 */

const CHUNK_BYTES = 4 * 1024 * 1024;

/** The cache the service worker serves /basemap-assets from. Keep in step with vite.config.ts. */
export const STREET_ASSETS_CACHE = 'geoverify-basemap-assets';

const FONTS = ['Noto Sans Regular', 'Noto Sans Medium', 'Noto Sans Italic'];
const RANGES = ['0-255', '256-511', '512-767', '768-1023', '7680-7935', '8192-8447'];
const SPRITES = ['light.json', 'light.png', 'light@2x.json', 'light@2x.png'];

export function streetAssetUrls(): string[] {
    return [
        ...FONTS.flatMap((font) => RANGES.map((range) => `/basemap-assets/fonts/${encodeURIComponent(font)}/${range}.pbf`)),
        ...SPRITES.map((sprite) => `/basemap-assets/sprites/${sprite}`),
    ];
}

export interface StreetsMeta {
    coverageAreaId: number;
    mandate: string;
    bytes: number;
    megabytes: number;
    checksum: string;
    bounds: [number, number, number, number];
    maxZoom: number;
    url: string;
}

/** What the server says this officer could be holding. */
export async function availableStreets(): Promise<StreetsMeta[]> {
    const response = await fetch('/api/field/streets', { headers: { Accept: 'application/json' } });

    if (!response.ok) {
        throw new Error('Could not ask the server which street maps exist.');
    }

    const body = (await response.json()) as { streets: StreetsMeta[] };

    return body.streets;
}

export async function localStreets(coverageAreaId: number): Promise<LocalStreets | undefined> {
    const held = await db.streets.get(coverageAreaId);

    // A part downloaded map is never opened.
    return held?.blob == null ? undefined : held;
}

/** Puts the fonts and icons where the worker will find them offline. */
async function cacheStreetAssets(): Promise<void> {
    if (!('caches' in window)) {
        return;
    }

    const cache = await caches.open(STREET_ASSETS_CACHE);

    await Promise.all(
        streetAssetUrls().map(async (url) => {
            if ((await cache.match(url)) === undefined) {
                // One missing glyph range only costs some names; it is not worth failing the map.
                await cache.add(url).catch(() => undefined);
            }
        }),
    );
}

export async function installStreets(
    meta: StreetsMeta,
    onProgress: (progress: { received: number; bytes: number }) => void,
    signal?: AbortSignal,
): Promise<LocalStreets> {
    const existing = await db.streets.get(meta.coverageAreaId);

    if (existing?.blob != null && existing.checksum === meta.checksum) {
        await cacheStreetAssets();

        return existing;
    }

    // A different cut: whatever was here, whole or partial, is of the old one.
    if (existing !== undefined && existing.checksum !== meta.checksum) {
        await discardStreets(meta.coverageAreaId);
    }

    const held = await db.streetChunks.where('coverageAreaId').equals(meta.coverageAreaId).count();
    let received = held * CHUNK_BYTES;
    let index = held;

    const base: Omit<LocalStreets, 'received' | 'blob' | 'installedAt'> = {
        coverageAreaId: meta.coverageAreaId,
        mandate: meta.mandate,
        checksum: meta.checksum,
        bytes: meta.bytes,
        maxZoom: meta.maxZoom,
        bounds: meta.bounds,
    };

    await db.streets.put({ ...base, received, blob: null, installedAt: null });
    onProgress({ received, bytes: meta.bytes });

    if (received < meta.bytes) {
        const init: RequestInit = received > 0 ? { headers: { Range: `bytes=${String(received)}-` } } : {};

        if (signal !== undefined) {
            init.signal = signal;
        }

        const response = await fetch(meta.url, init);

        if (!response.ok || response.body === null) {
            throw new Error(`The street map could not be fetched (${String(response.status)}).`);
        }

        // A server that ignored Range sent the whole file: start over rather
        // than write its beginning into the middle.
        if (received > 0 && response.status !== 206) {
            await db.streetChunks.where('coverageAreaId').equals(meta.coverageAreaId).delete();
            received = 0;
            index = 0;
        }

        const reader = response.body.getReader();
        let buffered: BlobPart[] = [];
        let bufferedBytes = 0;

        const flush = async (): Promise<void> => {
            if (bufferedBytes === 0) {
                return;
            }

            await db.streetChunks.put({ coverageAreaId: meta.coverageAreaId, index, blob: new Blob(buffered) });
            index += 1;
            buffered = [];
            bufferedBytes = 0;
            await db.streets.update(meta.coverageAreaId, { received });
        };

        for (;;) {
            const { done, value } = await reader.read();

            if (done) {
                break;
            }

            buffered.push(value);
            bufferedBytes += value.byteLength;
            received += value.byteLength;
            onProgress({ received, bytes: meta.bytes });

            if (bufferedBytes >= CHUNK_BYTES) {
                await flush();
            }
        }

        await flush();
    }

    const parts = await db.streetChunks.where('coverageAreaId').equals(meta.coverageAreaId).sortBy('index');
    const blob = new Blob(parts.map((part) => part.blob));

    if (blob.size !== meta.bytes) {
        await discardStreets(meta.coverageAreaId);

        throw new Error(
            `The street map arrived incomplete, ${String(blob.size)} of ${String(meta.bytes)} bytes. Try again on a steadier connection.`,
        );
    }

    await cacheStreetAssets();

    const installed: LocalStreets = { ...base, received: blob.size, blob, installedAt: new Date().toISOString() };

    await db.streets.put(installed);
    await db.streetChunks.where('coverageAreaId').equals(meta.coverageAreaId).delete();

    return installed;
}

/** The street map is rebuildable on the server, so removing it is safe. */
export async function discardStreets(coverageAreaId: number): Promise<void> {
    await db.transaction('rw', db.streets, db.streetChunks, async () => {
        await db.streetChunks.where('coverageAreaId').equals(coverageAreaId).delete();
        await db.streets.delete(coverageAreaId);
    });
}

/** The archive on the device, ready for the pmtiles protocol. */
export function openStreets(streets: LocalStreets): { archive: PMTiles; url: string } | null {
    if (streets.blob === null) {
        return null;
    }

    const key = `device://streets-${String(streets.coverageAreaId)}-${streets.checksum.slice(0, 8)}`;

    return { archive: new PMTiles(new DeviceSource(streets.blob, key)), url: key };
}
