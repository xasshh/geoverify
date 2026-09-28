import { useEffect, useState } from 'react';
import { liveQuery } from 'dexie';
import { csrfToken, uuid7 } from '@/lib/capture';
import { db, type JobAction } from '@/lib/offline/db';

/**
 * An inspection job's outbox.
 *
 * Everything the agent does is written here first and sent in order when
 * there is signal. A refusal from the server (a 4xx with a reason) is kept
 * and shown, never retried blindly; a missing network just waits. Each action
 * carries a uuid the server keys on, so a resend after a dropped response is
 * one arrival, one photograph, one report.
 */
async function send(action: JobAction): Promise<'done' | 'refused' | 'offline'> {
    const base = `/api/field/jobs/${String(action.inspectionId)}`;
    let response: Response;

    try {
        if (action.type === 'photo') {
            const form = new FormData();
            form.append('client_uuid', action.uuid);
            form.append('photo', action.blob ?? new Blob(), 'inspection.jpg');

            for (const [key, value] of Object.entries(action.payload)) {
                // Photo fields are coordinates: numbers, never objects.
                if (typeof value === 'number' || typeof value === 'string') {
                    form.append(key, String(value));
                }
            }

            response = await fetch(`${base}/photos`, { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() }, body: form });
        } else {
            response = await fetch(`${base}/${action.type}`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
                body: JSON.stringify(action.payload),
            });
        }
    } catch {
        return 'offline';
    }

    if (response.ok) {
        await db.jobActions.update(action.seq ?? 0, { state: 'done', error: null, blob: null });

        return 'done';
    }

    if (response.status >= 500) {
        return 'offline';
    }

    const problem = (await response.json().catch(() => ({ message: '' }))) as { message?: string };
    await db.jobActions.update(action.seq ?? 0, { state: 'failed', error: problem.message ?? 'Refused.' });

    return 'refused';
}

let flushing = false;

export async function flushJobs(): Promise<void> {
    if (flushing) {
        return;
    }

    flushing = true;

    try {
        const queued = await db.jobActions.where('state').equals('queued').sortBy('seq');

        for (const action of queued) {
            // Stop at the first thing that cannot go: what follows it depends on it.
            if ((await send(action)) !== 'done') {
                return;
            }
        }
    } finally {
        flushing = false;
    }
}

export async function recordJobAction(inspectionId: number, type: JobAction['type'], payload: Record<string, unknown>, blob: Blob | null = null): Promise<void> {
    const uuid = uuid7();
    const withKey = type === 'report' ? { ...payload, report_uuid: uuid } : payload;

    await db.jobActions.add({ uuid, inspectionId, type, payload: withKey, blob, state: 'queued', error: null });
    void flushJobs();
}

/** Retry the refused ones after the agent has fixed what was wrong. */
export async function retryJob(inspectionId: number): Promise<void> {
    const failed = await db.jobActions.where('inspectionId').equals(inspectionId).filter((a) => a.state === 'failed').toArray();
    await Promise.all(failed.map((a) => db.jobActions.update(a.seq ?? 0, { state: 'queued', error: null })));
    void flushJobs();
}

export function useJobActions(inspectionId: number): JobAction[] {
    const [actions, setActions] = useState<JobAction[]>([]);

    useEffect(() => {
        const subscription = liveQuery(() => db.jobActions.where('inspectionId').equals(inspectionId).sortBy('seq')).subscribe({
            next: setActions,
            error: () => undefined,
        });

        void flushJobs();
        const retry = () => {
            void flushJobs();
        };
        window.addEventListener('online', retry);
        const timer = window.setInterval(retry, 20_000);

        return () => {
            subscription.unsubscribe();
            window.removeEventListener('online', retry);
            window.clearInterval(timer);
        };
    }, [inspectionId]);

    return actions;
}
