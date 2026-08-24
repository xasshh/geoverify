import { PMTiles, type RangeResponse, type Source } from 'pmtiles';
import { db, type LocalPack } from './db';

/**
 * The offline basemap.
 *
 * Without it the field map is a set of outlines floating on nothing: no streets,
 * no names, nothing an officer can orient by. The pack is one file holding every
 * tile for a mandate, fetched once on wifi and read from the device thereafter.
 * No tile server is contacted in the field, because in the field there is
 * nothing to contact.
 */

/** Written to the device as it arrives, so a dropped connection costs one chunk. */
const CHUNK_BYTES = 4 * 1024 * 1024;

export interface PackMeta {
    id: number;
    coverageAreaId: number;
    mandate: string;
    bytes: number;
    megabytes: number;
    secondsAt2Mbps: number;
    checksum: string;
    minZoom: number;
    maxZoom: number;
    layers: Record<string, number>;
    bounds: [number, number, number, number];
    builtAt: string;
    url: string;
}

export interface PackProgress {
    received: number;
    bytes: number;
}

/** What the server says this officer should be holding. */
export async function availablePacks(): Promise<PackMeta[]> {
    const response = await fetch('/api/field/packs', { headers: { Accept: 'application/json' } });

    if (!response.ok) {
        throw new Error('Could not ask the server which map packs exist.');
    }

    const body = (await response.json()) as { packs: PackMeta[] };

    return body.packs;
}

export async function localPack(coverageAreaId: number): Promise<LocalPack | undefined> {
    return db.packs.where('coverageAreaId').equals(coverageAreaId).first();
}

/**
 * Downloads a pack, resuming whatever is already here.
 *
 * One request, read as a stream, with the bytes already on the device requested
 * away by Range. Progress is real: it counts bytes written, not requests made.
 *
 * A pack whose checksum has changed is a different pack, so a part downloaded
 * predecessor is discarded rather than continued from. Continuing would produce
 * a file that is wrong in the middle and fails silently at a tile boundary
 * hours later, in the field, with no way to tell what happened.
 */
export async function installPack(
    meta: PackMeta,
    onProgress: (progress: PackProgress) => void,
    signal?: AbortSignal,
): Promise<LocalPack> {
    const existing = await db.packs.get(meta.id);

    if (existing?.blob != null && existing.checksum === meta.checksum) {
        return existing;
    }

    if (existing !== undefined && existing.checksum !== meta.checksum) {
        await discardPack(meta.id);
    }

    // Whole chunks only. A partial chunk was never written, so resuming from the
    // count of whole chunks can never leave a hole.
    const held = await db.packChunks.where('packId').equals(meta.id).count();
    let received = held * CHUNK_BYTES;
    let index = held;

    await db.packs.put({
        packId: meta.id,
        coverageAreaId: meta.coverageAreaId,
        mandate: meta.mandate,
        checksum: meta.checksum,
        bytes: meta.bytes,
        received,
        minZoom: meta.minZoom,
        maxZoom: meta.maxZoom,
        bounds: meta.bounds,
        layers: meta.layers,
        blob: null,
        installedAt: null,
    });

    onProgress({ received, bytes: meta.bytes });

    if (received < meta.bytes) {
        // Assembled rather than declared inline: with exactOptionalPropertyTypes
        // an undefined signal cannot be handed to fetch as a present property.
        const init: RequestInit =
            received > 0 ? { headers: { Range: `bytes=${String(received)}-` } } : {};

        if (signal !== undefined) {
            init.signal = signal;
        }

        const response = await fetch(meta.url, init);

        if (!response.ok || response.body === null) {
            throw new Error(`The pack could not be fetched (${String(response.status)}).`);
        }

        // A server that ignored the Range header sent the whole file. Start over
        // rather than write the beginning of the file into the middle of it.
        if (received > 0 && response.status !== 206) {
            await db.packChunks.where('packId').equals(meta.id).delete();
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

            await db.packChunks.put({ packId: meta.id, index, blob: new Blob(buffered) });

            index += 1;
            buffered = [];
            bufferedBytes = 0;
            await db.packs.update(meta.id, { received });
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

    // Assembled once, at the end. The parts stay on disk until the whole archive
    // exists, so an interrupted install never leaves something openable.
    const parts = await db.packChunks.where('packId').equals(meta.id).sortBy('index');
    const blob = new Blob(parts.map((part) => part.blob));

    if (blob.size !== meta.bytes) {
        await discardPack(meta.id);

        throw new Error(
            `The pack arrived incomplete, ${String(blob.size)} of ${String(meta.bytes)} bytes. Try again on a steadier connection.`,
        );
    }

    const installed: LocalPack = {
        packId: meta.id,
        coverageAreaId: meta.coverageAreaId,
        mandate: meta.mandate,
        checksum: meta.checksum,
        bytes: meta.bytes,
        received: blob.size,
        minZoom: meta.minZoom,
        maxZoom: meta.maxZoom,
        bounds: meta.bounds,
        layers: meta.layers,
        blob,
        installedAt: new Date().toISOString(),
    };

    await db.packs.put(installed);
    await db.packChunks.where('packId').equals(meta.id).delete();

    return installed;
}

/**
 * Removes a pack from the device.
 *
 * A pack is derived data, rebuildable from PostGIS at any time, so this is the
 * one thing in the app that genuinely deletes. Nothing an officer recorded is
 * touched.
 */
export async function discardPack(packId: number): Promise<void> {
    await db.transaction('rw', db.packs, db.packChunks, async () => {
        await db.packChunks.where('packId').equals(packId).delete();
        await db.packs.delete(packId);
    });
}

/**
 * Reads a pack out of IndexedDB for MapLibre.
 *
 * Blob.slice does not copy, so a tile request reads a few kilobytes off disk
 * rather than pulling 67 MB through memory to find them.
 */
class DeviceSource implements Source {
    constructor(
        private readonly blob: Blob,
        private readonly key: string,
    ) {}

    getKey(): string {
        return this.key;
    }

    async getBytes(offset: number, length: number): Promise<RangeResponse> {
        return { data: await this.blob.slice(offset, offset + length).arrayBuffer() };
    }
}

/** The archive on the device, ready to be handed to the pmtiles protocol. */
export function openPack(pack: LocalPack): { archive: PMTiles; url: string } | null {
    if (pack.blob === null) {
        return null;
    }

    const key = `device://pack-${String(pack.packId)}`;

    return { archive: new PMTiles(new DeviceSource(pack.blob, key)), url: key };
}
