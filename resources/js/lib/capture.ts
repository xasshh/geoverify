/**
 * The client side of capture.
 *
 * Every record carries a client generated UUID v7. Records are addressable
 * before the server has seen them, and v7 sorts chronologically, which is what
 * lets the offline queue at M5 drain in creation order without inventing a
 * sequence number.
 */

/**
 * The CSRF token, read from the cookie rather than the meta tag.
 *
 * The meta tag holds whatever the token was when the document was first
 * rendered. Logging in regenerates the session, and an Inertia visit only swaps
 * the page body, so that tag goes stale and every subsequent post is rejected
 * with a 419 the officer cannot do anything about. Laravel refreshes the
 * XSRF-TOKEN cookie on every response, so that is the one to trust.
 */
export function csrfToken(): string {
    const cookie = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='));

    if (cookie === undefined) {
        return '';
    }

    return decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
}

/**
 * UUID v7: a millisecond timestamp followed by randomness.
 *
 * crypto.randomUUID gives v4, which sorts arbitrarily. Ordering matters here
 * because a structure has to reach the server before the businesses inside it.
 */
export function uuid7(): string {
    const now = Date.now();
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);

    bytes[0] = (now / 2 ** 40) & 0xff;
    bytes[1] = (now / 2 ** 32) & 0xff;
    bytes[2] = (now / 2 ** 24) & 0xff;
    bytes[3] = (now / 2 ** 16) & 0xff;
    bytes[4] = (now / 2 ** 8) & 0xff;
    bytes[5] = now & 0xff;
    bytes[6] = 0x70 | ((bytes[6] ?? 0) & 0x0f);
    bytes[8] = 0x80 | ((bytes[8] ?? 0) & 0x3f);

    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export interface ApiError {
    /** Written for the officer, not for a log. Empty when the server sent none. */
    message: string;
    errors?: Record<string, string[]>;
}

async function post<T>(url: string, body: unknown): Promise<T> {
    const isForm = body instanceof FormData;

    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
            ...(isForm ? {} : { 'Content-Type': 'application/json' }),
        },
        body: isForm ? body : JSON.stringify(body),
    });

    if (!response.ok) {
        const problem = (await response.json().catch(() => ({ message: '' }))) as ApiError;

        // Surfaced as written by the server. These messages are meant for the
        // officer, not for a log.
        throw new Error(
            problem.message === '' ? 'That did not save. Check your connection and try again.' : problem.message,
        );
    }

    return (await response.json()) as T;
}

export interface StructureResult {
    id: number;
    client_uuid: string;
    status: string;
    resolved: { ward: string | null; lga: string | null; state: string | null };
    units_outstanding: number;
}

export function saveStructure(payload: Record<string, unknown>): Promise<StructureResult> {
    return post<StructureResult>('/api/field/structures', payload);
}

export interface EnterpriseResult {
    id: number;
    client_uuid: string;
    trading_name: string;
    sector_code: string | null;
    units_outstanding: number;
}

export function saveEnterprise(payload: Record<string, unknown>): Promise<EnterpriseResult> {
    return post<EnterpriseResult>('/api/field/enterprises', payload);
}

export interface SessionResult {
    id: number;
    client_uuid: string;
    started_at: string;
}

export function startSession(payload: Record<string, unknown>): Promise<SessionResult> {
    return post<SessionResult>('/api/field/sessions', payload);
}

export function sendFixes(sessionId: number, fixes: unknown[]): Promise<{ stored: number }> {
    return post<{ stored: number }>(`/api/field/sessions/${String(sessionId)}/fixes`, { fixes });
}

export interface PhotographResult {
    id: number;
    client_uuid: string;
    kind: string;
    bytes: number;
    distance_from_subject_m: number | null;
    from_device_camera: boolean | null;
}

/**
 * Compresses a photograph on the device before it is uploaded.
 *
 * A phone camera writes 4 to 12 MB per frame. On a 2G connection that is minutes
 * per photograph, and an officer capturing eighty structures a day would never
 * finish. Target is the longest edge at 1600px and roughly 400 KB, which is
 * comfortably enough to read a shop sign.
 *
 * createImageBitmap and an OffscreenCanvas keep the decode off the main thread,
 * so the interface does not freeze while a photograph is processed.
 */
export async function compressPhotograph(file: File, maxEdge = 1600, targetBytes = 400_000): Promise<Blob> {
    const bitmap = await createImageBitmap(file);
    const scale = Math.min(1, maxEdge / Math.max(bitmap.width, bitmap.height));
    const width = Math.round(bitmap.width * scale);
    const height = Math.round(bitmap.height * scale);

    const canvas =
        typeof OffscreenCanvas === 'undefined'
            ? Object.assign(document.createElement('canvas'), { width, height })
            : new OffscreenCanvas(width, height);

    const context = canvas.getContext('2d');

    if (context === null) {
        bitmap.close();

        return file;
    }

    context.drawImage(bitmap, 0, 0, width, height);
    bitmap.close();

    const toBlob = async (quality: number): Promise<Blob> =>
        canvas instanceof OffscreenCanvas
            ? canvas.convertToBlob({ type: 'image/jpeg', quality })
            : new Promise<Blob>((resolve) => {
                  canvas.toBlob((b) => {
                      resolve(b ?? file);
                  }, 'image/jpeg', quality);
              });

    // Two passes at most. A third would cost more battery than the bytes save.
    let blob = await toBlob(0.82);

    if (blob.size > targetBytes) {
        blob = await toBlob(0.65);
    }

    return blob;
}

export async function savePhotograph(
    file: File,
    fields: {
        structure_id: number;
        kind: string;
        device_longitude?: number;
        device_latitude?: number;
        field_session_id?: number;
    },
): Promise<PhotographResult> {
    const compressed = await compressPhotograph(file);
    const form = new FormData();

    form.append('photo', compressed, 'capture.jpg');
    form.append('client_uuid', uuid7());
    form.append('structure_id', String(fields.structure_id));
    form.append('kind', fields.kind);

    if (fields.device_longitude !== undefined) {
        form.append('device_longitude', String(fields.device_longitude));
    }
    if (fields.device_latitude !== undefined) {
        form.append('device_latitude', String(fields.device_latitude));
    }
    if (fields.field_session_id !== undefined) {
        form.append('field_session_id', String(fields.field_session_id));
    }

    return post<PhotographResult>('/api/field/photographs', form);
}
