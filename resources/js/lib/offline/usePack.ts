import { useCallback, useEffect, useRef, useState } from 'react';
import { availablePacks, installPack, localPack, type PackMeta } from './pack';
import type { LocalPack } from './db';

export type PackState =
    | 'checking'
    | 'absent'
    | 'available'
    | 'downloading'
    | 'installed'
    | 'stale'
    | 'error';

interface UsePack {
    state: PackState;
    /** The pack on the device, whole. Null while there is nothing to draw with. */
    pack: LocalPack | null;
    /** What the server is offering, so the size can be shown before committing. */
    offered: PackMeta | null;
    received: number;
    bytes: number;
    error: string | null;
    download: () => void;
    cancel: () => void;
}

/**
 * The officer's map pack for one mandate.
 *
 * Two questions, answered in this order: is there a map on this device, and if
 * not, what would it cost to fetch one. The second is answered in megabytes and
 * minutes before anything is downloaded, because an officer on a metered
 * connection is making a real decision and the app has no business making it
 * for them.
 *
 * Everything here tolerates having no network. A handset that already holds the
 * pack never needs the server to say so.
 */
export function usePack(coverageAreaId: number): UsePack {
    const [state, setState] = useState<PackState>('checking');
    const [pack, setPack] = useState<LocalPack | null>(null);
    const [offered, setOffered] = useState<PackMeta | null>(null);
    const [received, setReceived] = useState(0);
    const [bytes, setBytes] = useState(0);
    const [error, setError] = useState<string | null>(null);
    const abort = useRef<AbortController | null>(null);

    useEffect(() => {
        const controller = new AbortController();

        /**
         * Both questions asked before anything is decided.
         *
         * Every setState sits behind one abort check rather than several: a
         * check per await reads as dead code to the compiler and, worse, leaves
         * the state half updated when the screen has already gone.
         */
        const look = async (): Promise<void> => {
            const held = await localPack(coverageAreaId);

            // Asked even when a pack is already held, so a rebuilt pack is
            // noticed. Failing here is normal: it means no signal, which is the
            // condition this whole subsystem exists for.
            const offer = await availablePacks()
                .then((packs) => packs.find((p) => p.coverageAreaId === coverageAreaId) ?? null)
                .catch(() => null);

            if (controller.signal.aborted) {
                return;
            }

            const whole = held?.blob != null ? held : null;

            if (whole !== null) {
                setPack(whole);
                setBytes(whole.bytes);
                setReceived(whole.received);
            }

            if (offer !== null) {
                setOffered(offer);
            }

            if (whole !== null) {
                // A newer pack existing is an offer, never an interruption: the
                // one on the device still draws every building in the cell.
                setState(offer !== null && offer.checksum !== whole.checksum ? 'stale' : 'installed');

                return;
            }

            if (offer === null) {
                setState('absent');

                return;
            }

            setBytes(offer.bytes);
            setReceived(held?.received ?? 0);
            setState('available');
        };

        void look();

        return () => {
            controller.abort();
        };
    }, [coverageAreaId]);

    const download = useCallback(() => {
        if (offered === null) {
            return;
        }

        const controller = new AbortController();
        abort.current = controller;

        setState('downloading');
        setError(null);
        setBytes(offered.bytes);

        void installPack(
            offered,
            (progress) => {
                setReceived(progress.received);
            },
            controller.signal,
        )
            .then((installed) => {
                setPack(installed);
                setState('installed');
            })
            .catch((cause: unknown) => {
                if (controller.signal.aborted) {
                    setState('available');

                    return;
                }

                setError(cause instanceof Error ? cause.message : 'The map could not be fetched.');
                setState('error');
            });
    }, [offered]);

    const cancel = useCallback(() => {
        // Stopped, not discarded. What has already landed stays on the device and
        // the next attempt carries on from there.
        abort.current?.abort();
    }, []);

    return { state, pack, offered, received, bytes, error, download, cancel };
}
