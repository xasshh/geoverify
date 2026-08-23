import { useCallback, useEffect, useState } from 'react';
import { drain, enqueue, snapshot, startAutoSync, type QueueSnapshot } from './queue';
import type { Mutation } from './db';

/**
 * The officer's view of whether their work is safe.
 *
 * This is the single most anxiety-producing question in the field app, and the
 * interface has to answer it before it is asked. The queue count is not a
 * progress bar: it is the officer's evidence that nothing has been dropped.
 */
export function useOfflineQueue() {
    const [state, setState] = useState<QueueSnapshot>({
        queued: 0,
        failed: 0,
        deferred: 0,
        lastSyncAt: null,
        syncing: false,
    });

    const [online, setOnline] = useState(() => navigator.onLine);

    useEffect(() => {
        const stop = startAutoSync(setState);

        const goOnline = () => {
            setOnline(true);
        };
        const goOffline = () => {
            setOnline(false);
        };

        window.addEventListener('online', goOnline);
        window.addEventListener('offline', goOffline);

        return () => {
            stop();
            window.removeEventListener('online', goOnline);
            window.removeEventListener('offline', goOffline);
        };
    }, []);

    /**
     * Records something the officer did.
     *
     * Resolves as soon as IndexedDB has it. Whether the server hears in this
     * second is not the officer's problem and must not be their wait.
     */
    const record = useCallback(
        async (entity: Mutation['entity'], payload: Record<string, unknown>): Promise<string> => {
            const clientUuid = await enqueue(entity, payload);

            setState(await snapshot());

            // Sent opportunistically. If it fails, it stays queued and the auto
            // sync picks it up; nothing about that reaches the officer.
            void drain()
                .then(() => snapshot())
                .then(setState)
                .catch(() => undefined);

            return clientUuid;
        },
        [],
    );

    const syncNow = useCallback(async (): Promise<void> => {
        await drain();
        setState(await snapshot());
    }, []);

    return { ...state, online, record, syncNow };
}
