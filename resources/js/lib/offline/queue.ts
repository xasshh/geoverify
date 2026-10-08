import { db, readMeta, writeMeta, type Mutation } from './db';
import { drainAreaPhotographs } from './areaPhotos';
import { compressPhotograph, uuid7 } from '@/lib/capture';

/**
 * The sync engine.
 *
 * Local first: a capture is written to the device and enqueued, and that is the
 * end of the officer's involvement. Draining happens when it can, in creation
 * order, and never blocks anything the officer is doing.
 */

export interface SyncResult {
    client_uuid: string;
    status: 'applied' | 'duplicate' | 'deferred' | 'failed' | 'rejected';
    message?: string;
    id?: number;
    type?: string;
}

export interface QueueSnapshot {
    queued: number;
    failed: number;
    deferred: number;
    lastSyncAt: string | null;
    syncing: boolean;
}

/** Attempts before a mutation is parked for a supervisor to look at. */
const MAX_ATTEMPTS = 8;

function csrfToken(): string {
    const cookie = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));

    return cookie === undefined ? '' : decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
}

/**
 * Records a capture on the device and queues it.
 *
 * Returns as soon as IndexedDB has it. Whether the server ever hears about it in
 * this second is not the officer's problem.
 */
export async function enqueue(
    entity: Mutation['entity'],
    payload: Record<string, unknown>,
    op: Mutation['op'] = 'create',
): Promise<string> {
    const clientUuid = (payload.client_uuid as string | undefined) ?? uuid7();

    await db.mutations.put({
        clientUuid,
        entity,
        op,
        payload: { ...payload, client_uuid: clientUuid },
        createdAt: new Date().toISOString(),
        state: 'queued',
        attempts: 0,
        lastError: null,
        serverId: null,
    });

    return clientUuid;
}

/**
 * Holds a photograph on the device.
 *
 * Compressed once, here, rather than at upload time: a handset that has been
 * offline for a day should not be holding a dozen 12 MB frames, and doing the
 * work while the officer is standing still costs less battery than doing it all
 * at once when signal returns.
 *
 * It cannot be uploaded until its building has a server id, so it waits. The
 * officer is never told to wait.
 */
export async function holdPhotograph(
    structureClientUuid: string,
    kind: string,
    file: File,
    position: { longitude: number; latitude: number } | null,
): Promise<string> {
    const clientUuid = uuid7();
    const blob = await compressPhotograph(file);

    await db.photos.put({
        clientUuid,
        structureClientUuid,
        kind,
        blob,
        bytes: blob.size,
        longitude: position?.longitude ?? null,
        latitude: position?.latitude ?? null,
        takenAt: new Date().toISOString(),
        serverId: null,
    });

    return clientUuid;
}

/**
 * Sends photographs whose building the server now knows about.
 *
 * Always after the records, never with them. A large photograph on a slow
 * connection must never be able to delay a capture from arriving.
 */
async function drainPhotographs(): Promise<number> {
    const waiting = await db.photos.filter((p) => p.serverId === null).toArray();
    let sent = 0;

    for (const photo of waiting) {
        const structure = await db.structures.get(photo.structureClientUuid);

        if (structure?.serverId == null) {
            // Its building has not landed yet. Nothing to do but wait.
            continue;
        }

        const form = new FormData();
        form.append('photo', photo.blob, 'capture.jpg');
        form.append('client_uuid', photo.clientUuid);
        form.append('structure_id', String(structure.serverId));
        form.append('kind', photo.kind);

        if (photo.longitude !== null && photo.latitude !== null) {
            form.append('device_longitude', String(photo.longitude));
            form.append('device_latitude', String(photo.latitude));
        }

        try {
            const response = await fetch('/api/field/photographs', {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
                body: form,
            });

            if (!response.ok) {
                continue;
            }

            const body = (await response.json()) as { id: number };
            await db.photos.update(photo.clientUuid, { serverId: body.id });
            sent += 1;
        } catch {
            // Still on the device. Tried again next pass.
        }
    }

    return sent;
}

export async function snapshot(): Promise<QueueSnapshot> {
    const [queued, failed, deferred, photos, lastSyncAt] = await Promise.all([
        db.mutations.where('state').anyOf('queued', 'sending').count(),
        db.mutations.where('state').equals('failed').count(),
        db.mutations.where('state').equals('deferred').count(),
        db.photos.filter((p) => p.serverId === null).count().then(async (n) => n + (await db.areaPhotos.filter((p) => p.serverId === null).count())),
        readMeta<string | null>('lastSyncAt', null),
    ]);

    // Photographs count toward what the officer sees as waiting. They are part
    // of the day's work, and a queue that hides them would be lying.
    return { queued: queued + photos, failed, deferred, lastSyncAt, syncing: draining };
}

let draining = false;

/**
 * Sends everything waiting, oldest first.
 *
 * Deferred mutations are re-sent rather than parked: a business whose building
 * has not landed yet will land on the next pass, once the building has.
 */
export async function drain(batchSize = 50): Promise<SyncResult[]> {
    if (draining || !navigator.onLine) {
        return [];
    }

    draining = true;

    try {
        const pending = await db.mutations
            .where('state')
            .anyOf('queued', 'deferred')
            .sortBy('clientUuid');

        if (pending.length === 0) {
            await drainPhotographs();
        await drainAreaPhotographs();
            await writeMeta('lastSyncAt', new Date().toISOString());

            return [];
        }

        const results: SyncResult[] = [];

        // Batched, because one request per capture on a connection that can
        // barely carry the captures is how a day's work fails to arrive.
        for (let i = 0; i < pending.length; i += batchSize) {
            const batch = pending.slice(i, i + batchSize);

            await db.mutations
                .where('clientUuid')
                .anyOf(batch.map((m) => m.clientUuid))
                .modify({ state: 'sending' });

            let batchResults: SyncResult[];

            try {
                batchResults = await send(batch);
            } catch {
                // The connection went away mid batch. Put them back and stop;
                // nothing is lost and the next attempt picks up where this left.
                await db.mutations
                    .where('clientUuid')
                    .anyOf(batch.map((m) => m.clientUuid))
                    .modify((m) => {
                        m.state = 'queued';
                        m.attempts += 1;
                    });

                break;
            }

            await applyResults(batchResults);
            results.push(...batchResults);
        }

        // Records first, always. Photographs follow once their building has an id.
        await drainPhotographs();
        await drainAreaPhotographs();
        await writeMeta('lastSyncAt', new Date().toISOString());

        return results;
    } finally {
        draining = false;
    }
}

async function send(batch: Mutation[]): Promise<SyncResult[]> {
    const response = await fetch('/api/field/sync', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({
            mutations: batch.map((m) => ({
                client_uuid: m.clientUuid,
                entity: m.entity,
                op: m.op,
                payload: m.payload,
            })),
        }),
    });

    if (!response.ok) {
        throw new Error(`sync failed with ${String(response.status)}`);
    }

    const body = (await response.json()) as { results: SyncResult[] };

    return body.results;
}

async function applyResults(results: SyncResult[]): Promise<void> {
    await db.transaction('rw', db.mutations, db.structures, db.enterprises, async () => {
        for (const result of results) {
            const mutation = await db.mutations.get(result.client_uuid);

            if (mutation === undefined) {
                continue;
            }

            if (result.status === 'applied' || result.status === 'duplicate') {
                // Kept rather than deleted, so a mutation that arrives again from
                // a stale tab is recognised instead of re-sent.
                await db.mutations.update(result.client_uuid, {
                    state: 'done',
                    serverId: result.id ?? null,
                    lastError: null,
                });

                if (mutation.entity === 'structure' && result.id !== undefined) {
                    await db.structures.update(result.client_uuid, { serverId: result.id });
                }

                if (mutation.entity === 'enterprise' && result.id !== undefined) {
                    await db.enterprises.update(result.client_uuid, { serverId: result.id });
                }

                continue;
            }

            if (result.status === 'deferred') {
                // Its parent has not landed yet. Tried again next pass.
                await db.mutations.update(result.client_uuid, {
                    state: 'deferred',
                    lastError: result.message ?? null,
                });

                continue;
            }

            const attempts = mutation.attempts + 1;

            await db.mutations.update(result.client_uuid, {
                // Parked, not discarded. A mutation the server will never accept
                // still represents work an officer did, and a supervisor needs to
                // see it rather than have it vanish.
                state: attempts >= MAX_ATTEMPTS ? 'failed' : 'queued',
                attempts,
                lastError: result.message ?? 'The server would not accept this.',
            });
        }
    });
}

/**
 * Drains whenever there is a reason to.
 *
 * Connectivity returning is the obvious one. The others matter as much: an
 * officer switching back to the app, and a plain interval for the case where the
 * browser never fires an online event at all, which happens on Android more
 * often than the specification suggests.
 */
export function startAutoSync(onChange: (snapshot: QueueSnapshot) => void): () => void {
    let stopped = false;

    const run = () => {
        if (stopped) {
            return;
        }

        void drain()
            .then(() => snapshot())
            .then(onChange)
            .catch(() => undefined);
    };

    const onVisible = () => {
        if (document.visibilityState === 'visible') {
            run();
        }
    };

    window.addEventListener('online', run);
    window.addEventListener('offline', () => {
        void snapshot().then(onChange);
    });
    document.addEventListener('visibilitychange', onVisible);

    const timer = window.setInterval(run, 30_000);

    run();

    return () => {
        stopped = true;
        window.removeEventListener('online', run);
        document.removeEventListener('visibilitychange', onVisible);
        window.clearInterval(timer);
    };
}
