import { PMTiles } from 'pmtiles';
import { db, readMeta, writeMeta, type LocalImagery } from './db';
import { DeviceSource } from './pack';

/**
 * Satellite imagery, carried offline beside the map pack.
 *
 * The same discipline as pack.ts, kept in its own module and its own tables so
 * the pack code an officer already depends on is not touched: resumed by Range
 * a chunk at a time, assembled only when every byte is here, and a changed
 * checksum means a different image, never a continuation of the old one.
 */

const CHUNK_BYTES = 4 * 1024 * 1024;

export interface ImageryMeta {
    id: number;
    coverageAreaId: number;
    mandate: string;
    name: string;
    source: string;
    captured: string | null;
    capturedFrom: string | null;
    capturedTo: string | null;
    resolutionCm: number | null;
    licence: string | null;
    bytes: number;
    megabytes: number;
    checksum: string;
    minZoom: number;
    maxZoom: number;
    bounds: [number, number, number, number];
    url: string;
}

export type Basemap = 'street' | 'satellite';

export interface BasemapChoice {
    basemap: Basemap;
    /** 0 to 1. Below 1 the street map shows through the image. */
    opacity: number;
}

/** What the server says this officer could be holding. */
export async function availableImagery(): Promise<ImageryMeta[]> {
    const response = await fetch('/api/field/imagery', { headers: { Accept: 'application/json' } });

    if (!response.ok) {
        throw new Error('Could not ask the server which imagery exists.');
    }

    const body = (await response.json()) as { imagery: ImageryMeta[] };

    return body.imagery;
}

export async function localImagery(coverageAreaId: number): Promise<LocalImagery | undefined> {
    const held = await db.imagery.where('coverageAreaId').equals(coverageAreaId).toArray();

    // Newest complete image first; a part downloaded one is never opened.
    return held
        .filter((image) => image.blob !== null)
        .sort((a, b) => b.layerId - a.layerId)[0];
}

export async function installImagery(
    meta: ImageryMeta,
    onProgress: (progress: { received: number; bytes: number }) => void,
    signal?: AbortSignal,
): Promise<LocalImagery> {
    const existing = await db.imagery.get(meta.id);

    if (existing?.blob != null && existing.checksum === meta.checksum) {
        return existing;
    }

    if (existing !== undefined && existing.checksum !== meta.checksum) {
        await discardImagery(meta.id);
    }

    const held = await db.imageryChunks.where('layerId').equals(meta.id).count();
    let received = held * CHUNK_BYTES;
    let index = held;

    const base: Omit<LocalImagery, 'received' | 'blob' | 'installedAt'> = {
        layerId: meta.id,
        coverageAreaId: meta.coverageAreaId,
        mandate: meta.mandate,
        captured: meta.captured,
        licence: meta.licence,
        checksum: meta.checksum,
        bytes: meta.bytes,
        minZoom: meta.minZoom,
        maxZoom: meta.maxZoom,
        bounds: meta.bounds,
    };

    await db.imagery.put({ ...base, received, blob: null, installedAt: null });
    onProgress({ received, bytes: meta.bytes });

    if (received < meta.bytes) {
        const init: RequestInit = received > 0 ? { headers: { Range: `bytes=${String(received)}-` } } : {};

        if (signal !== undefined) {
            init.signal = signal;
        }

        const response = await fetch(meta.url, init);

        if (!response.ok || response.body === null) {
            throw new Error(`The imagery could not be fetched (${String(response.status)}).`);
        }

        // A server that ignored Range sent the whole file: start over rather
        // than write its beginning into the middle.
        if (received > 0 && response.status !== 206) {
            await db.imageryChunks.where('layerId').equals(meta.id).delete();
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

            await db.imageryChunks.put({ layerId: meta.id, index, blob: new Blob(buffered) });
            index += 1;
            buffered = [];
            bufferedBytes = 0;
            await db.imagery.update(meta.id, { received });
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

    const parts = await db.imageryChunks.where('layerId').equals(meta.id).sortBy('index');
    const blob = new Blob(parts.map((part) => part.blob));

    if (blob.size !== meta.bytes) {
        await discardImagery(meta.id);

        throw new Error(
            `The imagery arrived incomplete, ${String(blob.size)} of ${String(meta.bytes)} bytes. Try again on a steadier connection.`,
        );
    }

    const installed: LocalImagery = { ...base, received: blob.size, blob, installedAt: new Date().toISOString() };

    await db.imagery.put(installed);
    await db.imageryChunks.where('layerId').equals(meta.id).delete();

    // An older image of the same mandate is now only taking space.
    const older = await db.imagery.where('coverageAreaId').equals(meta.coverageAreaId).toArray();

    for (const image of older) {
        if (image.layerId !== meta.id) {
            await discardImagery(image.layerId);
        }
    }

    return installed;
}

/** Imagery is derived data, rebuildable on the server, so removing it is safe. */
export async function discardImagery(layerId: number): Promise<void> {
    await db.transaction('rw', db.imagery, db.imageryChunks, async () => {
        await db.imageryChunks.where('layerId').equals(layerId).delete();
        await db.imagery.delete(layerId);
    });
}

/** The archive on the device, ready for the pmtiles protocol. */
export function openImagery(image: LocalImagery): { archive: PMTiles; url: string } | null {
    if (image.blob === null) {
        return null;
    }

    const key = `device://imagery-${String(image.layerId)}`;

    return { archive: new PMTiles(new DeviceSource(image.blob, key)), url: key };
}

/** The officer's last choice for this mandate: street map unless they said otherwise. */
export async function readBasemapChoice(coverageAreaId: number): Promise<BasemapChoice> {
    return readMeta<BasemapChoice>(`basemap:${String(coverageAreaId)}`, { basemap: 'street', opacity: 1 });
}

export async function writeBasemapChoice(coverageAreaId: number, choice: BasemapChoice): Promise<void> {
    await writeMeta(`basemap:${String(coverageAreaId)}`, choice);
}
