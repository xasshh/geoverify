import { compressPhotograph, csrfToken, uuid7 } from '@/lib/capture';
import { db } from './db';

/**
 * Photographs of area features: held on the device, sent after their capture.
 *
 * The same discipline as building photographs (compressed once, never sent
 * ahead of the record, retried until they land) in a table of their own, so
 * the building queue never waits on a capture it does not know about.
 */
export async function holdAreaPhotograph(
    revisionClientUuid: string,
    file: File,
    position: { longitude: number; latitude: number } | null,
    bearing: number | null,
): Promise<string> {
    const clientUuid = uuid7();
    const blob = await compressPhotograph(file);

    await db.areaPhotos.put({
        clientUuid,
        revisionClientUuid,
        kind: 'area_photo',
        blob,
        bytes: blob.size,
        longitude: position?.longitude ?? null,
        latitude: position?.latitude ?? null,
        bearing,
        takenAt: new Date().toISOString(),
        serverId: null,
    });

    return clientUuid;
}

/** Sends photographs whose capture the server has accepted. */
export async function drainAreaPhotographs(): Promise<number> {
    const waiting = await db.areaPhotos.filter((p) => p.serverId === null).toArray();
    let sent = 0;

    for (const photo of waiting) {
        const capture = await db.mutations.get(photo.revisionClientUuid);

        if (capture?.state !== 'done') {
            // Its capture has not landed yet. Nothing to do but wait.
            continue;
        }

        const form = new FormData();
        form.append('photo', photo.blob, 'area.jpg');
        form.append('client_uuid', photo.clientUuid);
        form.append('revision_client_uuid', photo.revisionClientUuid);

        if (photo.longitude !== null && photo.latitude !== null) {
            form.append('device_longitude', String(photo.longitude));
            form.append('device_latitude', String(photo.latitude));
        }

        if (photo.bearing !== null) {
            form.append('bearing', String(photo.bearing));
        }

        try {
            const response = await fetch('/api/field/area-photographs', {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
                body: form,
            });

            if (!response.ok) {
                continue;
            }

            const body = (await response.json()) as { id: number };
            await db.areaPhotos.update(photo.clientUuid, { serverId: body.id });
            sent += 1;
        } catch {
            // Still on the device. Tried again next pass.
        }
    }

    return sent;
}
