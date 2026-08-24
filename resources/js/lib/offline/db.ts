import Dexie, { type EntityTable } from 'dexie';

/**
 * The officer's device is the source of truth until the server has heard.
 *
 * Every write commits here and is done at that moment. Nothing in the capture
 * flow waits on a server, because an officer working a cell with no signal must
 * never watch a spinner, and must never be able to lose a day's work by closing
 * the app, running out of battery, or walking into a dead spot.
 */

/** A record the officer has created, as it exists on the device. */
export interface LocalStructure {
    clientUuid: string;
    gridCellId: number;
    assignmentId: number;
    longitude: number;
    latitude: number;
    accuracyM: number | null;
    structureType: string;
    occupancyStatus: string;
    unitCount: number | null;
    floors: number | null;
    notes: string | null;
    observedAt: string;
    /** Filled in once the server has accepted it. Null while it is only local. */
    serverId: number | null;
    resolvedWard: string | null;
    /**
     * The footprint the officer tapped, if they tapped one.
     *
     * Null is a real answer: a kiosk between two buildings has no footprint, and
     * an officer must never be forced to attach one that is wrong.
     */
    externalFootprintId: number | null;
}

export interface LocalEnterprise {
    clientUuid: string;
    structureClientUuid: string;
    unitLabel: string | null;
    tradingName: string;
    sectorCode: string | null;
    scaleBand: string | null;
    signageObserved: boolean;
    observedAt: string;
    serverId: number | null;
}

/** A photograph, held as a blob until it has somewhere to go. */
export interface LocalPhoto {
    clientUuid: string;
    structureClientUuid: string;
    kind: string;
    blob: Blob;
    bytes: number;
    longitude: number | null;
    latitude: number | null;
    takenAt: string;
    serverId: number | null;
}

export interface LocalFix {
    id?: number;
    sessionClientUuid: string;
    longitude: number;
    latitude: number;
    accuracyM: number | null;
    recordedAt: string;
    sent: 0 | 1;
}

export type MutationState = 'queued' | 'sending' | 'done' | 'failed' | 'deferred';

/**
 * One thing the officer did, waiting to be told to the server.
 *
 * Append only and drained in creation order. The id is a UUID v7 so ordering
 * comes from the value itself: a building is always told before the businesses
 * inside it, without the client having to maintain a sequence.
 */
export interface Mutation {
    clientUuid: string;
    entity: 'structure' | 'enterprise';
    op: 'create' | 'update';
    payload: Record<string, unknown>;
    createdAt: string;
    state: MutationState;
    attempts: number;
    lastError: string | null;
    /** Set when the server has accepted it, so a retry can be answered locally. */
    serverId: number | null;
}

export interface Meta {
    key: string;
    value: unknown;
}

/**
 * A downloaded map pack.
 *
 * The blob is the whole PMTiles archive, which is tens of megabytes. It is held
 * as a Blob rather than an ArrayBuffer on purpose: the browser keeps it on disk
 * and MapLibre reads slices of it, so the map never pulls the archive into
 * memory to draw a tile.
 */
export interface LocalPack {
    packId: number;
    coverageAreaId: number;
    mandate: string;
    checksum: string;
    bytes: number;
    /** Bytes on the device. Below `bytes` means the download stopped partway. */
    received: number;
    minZoom: number;
    maxZoom: number;
    bounds: [number, number, number, number];
    layers: Record<string, number>;
    /** Set only when the whole archive is here. A partial pack is never opened. */
    blob: Blob | null;
    installedAt: string | null;
}

/**
 * A piece of a download in progress.
 *
 * A 67 MB file on a connection that drops must resume, not restart. Chunks are
 * written as they land, so an officer who loses wifi at 60 MB has 60 MB.
 */
export interface PackChunk {
    packId: number;
    index: number;
    blob: Blob;
}

const db = new Dexie('geoverify') as Dexie & {
    structures: EntityTable<LocalStructure, 'clientUuid'>;
    enterprises: EntityTable<LocalEnterprise, 'clientUuid'>;
    photos: EntityTable<LocalPhoto, 'clientUuid'>;
    fixes: EntityTable<LocalFix, 'id'>;
    mutations: EntityTable<Mutation, 'clientUuid'>;
    meta: EntityTable<Meta, 'key'>;
    packs: EntityTable<LocalPack, 'packId'>;
    packChunks: EntityTable<PackChunk, 'packId'>;
};

db.version(1).stores({
    // Indexed by what the app actually queries: a cell's structures, a
    // building's businesses, and the queue in creation order.
    structures: 'clientUuid, gridCellId, assignmentId, serverId',
    enterprises: 'clientUuid, structureClientUuid, serverId',
    photos: 'clientUuid, structureClientUuid, serverId',
    fixes: '++id, sessionClientUuid, sent',
    mutations: 'clientUuid, state, createdAt',
    meta: 'key',
});

// The offline basemap. Added in its own version so an officer upgrading mid
// deployment keeps every capture already on the device.
db.version(2).stores({
    packs: 'packId, coverageAreaId',
    packChunks: '[packId+index], packId',
});

export { db };

/** Small helpers so callers never touch the meta table's shape. */
export async function readMeta<T>(key: string, fallback: T): Promise<T> {
    const row = await db.meta.get(key);

    return row === undefined ? fallback : (row.value as T);
}

export async function writeMeta(key: string, value: unknown): Promise<void> {
    await db.meta.put({ key, value });
}
