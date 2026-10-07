import { useCallback, useEffect, useRef, useState } from 'react';
import {
    availableImagery,
    installImagery,
    localImagery,
    readBasemapChoice,
    writeBasemapChoice,
    type BasemapChoice,
    type ImageryMeta,
} from './imagery';
import type { LocalImagery } from './db';

export type ImageryState = 'checking' | 'absent' | 'available' | 'downloading' | 'installed' | 'stale' | 'error';

/**
 * Satellite imagery for one mandate: whether it is on the device, what the
 * server offers, and the officer's street or satellite choice.
 *
 * Optional throughout. A mandate with no imagery, or a phone with no signal,
 * answers "absent" and the map draws exactly as it always has.
 */
export function useImagery(coverageAreaId: number | null) {
    const [state, setState] = useState<ImageryState>('checking');
    const [image, setImage] = useState<LocalImagery | null>(null);
    const [offered, setOffered] = useState<ImageryMeta | null>(null);
    const [received, setReceived] = useState(0);
    const [error, setError] = useState<string | null>(null);
    const [choice, setChoice] = useState<BasemapChoice>({ basemap: 'street', opacity: 1 });
    const abort = useRef<AbortController | null>(null);

    useEffect(() => {
        if (coverageAreaId === null) {
            return;
        }

        const controller = new AbortController();

        const look = async (): Promise<void> => {
            const held = (await localImagery(coverageAreaId)) ?? null;
            const remembered = await readBasemapChoice(coverageAreaId);
            const offer = await availableImagery()
                .then((list) => list.find((i) => i.coverageAreaId === coverageAreaId) ?? null)
                .catch(() => null);

            if (controller.signal.aborted) {
                return;
            }

            setImage(held);
            setOffered(offer);
            setChoice(held === null ? { basemap: 'street', opacity: 1 } : remembered);

            if (held !== null) {
                setState(offer !== null && offer.checksum !== held.checksum ? 'stale' : 'installed');
            } else {
                setState(offer === null ? 'absent' : 'available');
            }
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

        void installImagery(
            offered,
            (progress) => {
                setReceived(progress.received);
            },
            controller.signal,
        )
            .then((installed) => {
                setImage(installed);
                setState('installed');
            })
            .catch((cause: unknown) => {
                if (controller.signal.aborted) {
                    setState('available');

                    return;
                }

                setError(cause instanceof Error ? cause.message : 'The imagery could not be fetched.');
                setState('error');
            });
    }, [offered]);

    const cancel = useCallback(() => {
        abort.current?.abort();
    }, []);

    const choose = useCallback(
        (next: BasemapChoice) => {
            setChoice(next);

            if (coverageAreaId !== null) {
                void writeBasemapChoice(coverageAreaId, next);
            }
        },
        [coverageAreaId],
    );

    return { state, image, offered, received, error, choice, download, cancel, choose };
}
