import { useCallback, useEffect, useState } from 'react';
import type { LocalStreets } from './db';
import { availableStreets, installStreets, localStreets, type StreetsMeta } from './streets';

export type StreetsState = 'checking' | 'absent' | 'downloading' | 'installed' | 'error';

/**
 * The street map for one mandate: on the device, or on its way.
 *
 * Fetched without asking. It is a few megabytes against a pack of a hundred,
 * and an officer should not have to find a button to get street names. A
 * phone with no signal keeps whatever it holds; one with nothing yet simply
 * draws the map as it always did.
 *
 * Several screens can ask for the same mandate at once (the board and the
 * capture screen), so a download in flight is shared rather than started twice.
 */

const inFlight = new Map<number, Promise<LocalStreets>>();
const progress = new Map<number, number>();

export function useStreets(coverageAreaId: number | null) {
    const [state, setState] = useState<StreetsState>('checking');
    const [streets, setStreets] = useState<LocalStreets | null>(null);
    const [offered, setOffered] = useState<StreetsMeta | null>(null);
    const [received, setReceived] = useState(0);
    const [error, setError] = useState<string | null>(null);
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        if (coverageAreaId === null) {
            return;
        }

        const controller = new AbortController();
        // A call, not a property read, so each check after an await reads it afresh.
        const stopped = (): boolean => controller.signal.aborted;

        const run = async (): Promise<void> => {
            const held = (await localStreets(coverageAreaId)) ?? null;

            if (stopped()) {
                return;
            }

            setStreets(held);
            setState(held === null ? 'checking' : 'installed');

            const offer = await availableStreets()
                .then((list) => list.find((s) => s.coverageAreaId === coverageAreaId) ?? null)
                .catch(() => null);

            if (stopped()) {
                return;
            }

            setOffered(offer);

            // Nothing to fetch: either there is no cut, or the one held is current.
            if (offer === null || (held !== null && held.checksum === offer.checksum)) {
                setState(held === null ? 'absent' : 'installed');

                return;
            }

            setState('downloading');
            setError(null);

            let pending = inFlight.get(coverageAreaId);

            if (pending === undefined) {
                pending = installStreets(offer, (p) => {
                    progress.set(coverageAreaId, p.received);
                }).finally(() => {
                    inFlight.delete(coverageAreaId);
                    progress.delete(coverageAreaId);
                });
                inFlight.set(coverageAreaId, pending);
            }

            const ticker = window.setInterval(() => {
                setReceived(progress.get(coverageAreaId) ?? 0);
            }, 500);

            try {
                const installed = await pending;

                if (!stopped()) {
                    setStreets(installed);
                    setState('installed');
                }
            } catch (cause: unknown) {
                if (!stopped()) {
                    setError(cause instanceof Error ? cause.message : 'The street map could not be fetched.');
                    setState('error');
                }
            } finally {
                window.clearInterval(ticker);
            }
        };

        void run();

        return () => {
            controller.abort();
        };
    }, [coverageAreaId, attempt]);

    const retry = useCallback(() => {
        setAttempt((n) => n + 1);
    }, []);

    return { state, streets, offered, received, error, retry };
}
