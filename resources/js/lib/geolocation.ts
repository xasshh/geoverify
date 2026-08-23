import { useCallback, useEffect, useRef, useState } from 'react';

export interface Fix {
    longitude: number;
    latitude: number;
    accuracy_m: number | null;
    recorded_at: string;
    speed_mps: number | null;
    heading: number | null;
    altitude_m: number | null;
}

interface TraceState {
    current: Fix | null;
    /**
     * How many fixes are waiting to be sent. The fixes themselves live in a ref,
     * not here: keeping the array in state would re-render the tree on every
     * fix, which is exactly the cost this hook exists to avoid.
     */
    pendingCount: number;
    /**
     * The path walked so far, for the Presence Mark. Capped, because a full day
     * is thousands of points and the mark is 150px across.
     */
    track: Array<[number, number]>;
    permission: 'unknown' | 'granted' | 'denied';
    wakeLock: 'held' | 'denied' | 'unsupported' | 'idle';
    error: string | null;
}

/**
 * Distance between two fixes, in metres.
 *
 * The only place in this system that measures on the client, and it measures
 * nothing that is kept: it decides whether the officer has moved enough to be
 * worth another fix. Every distance in the register is computed by PostGIS.
 */
function metresBetween(a: Fix, b: Fix): number {
    const R = 6_371_000;
    const toRad = (d: number) => (d * Math.PI) / 180;
    const dLat = toRad(b.latitude - a.latitude);
    const dLon = toRad(b.longitude - a.longitude);
    const lat1 = toRad(a.latitude);
    const lat2 = toRad(b.latitude);

    const h =
        Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon / 2) ** 2;

    return 2 * R * Math.asin(Math.sqrt(h));
}

/**
 * Records the officer's trace.
 *
 * Battery is the constraint that shapes this:
 *
 *  - Fixes are taken at 5s or 10m while moving, and the interval backs off to
 *    30s once positions cluster inside the accuracy radius. A stationary officer
 *    during a long interview is the common case, and polling them every five
 *    seconds for twenty minutes buys nothing.
 *  - The current position lives in a ref and drives the marker imperatively.
 *    Putting it in state would re-render the tree on every fix.
 *  - A wake lock is held only while a session is open, and released the moment
 *    it ends. If the lock is refused the officer is told, because a locked
 *    screen means a lost trace and finding that out at the end of the day is
 *    the worst possible time.
 */
export function useTrace(active: boolean) {
    const [state, setState] = useState<TraceState>(() => ({
        current: null,
        pendingCount: 0,
        track: [],
        permission: 'unknown',
        // Knowable at first render, so it does not need an effect to discover it.
        wakeLock: 'wakeLock' in navigator ? 'idle' : 'unsupported',
        error: null,
    }));

    const latest = useRef<Fix | null>(null);
    const pending = useRef<Fix[]>([]);
    const lastKept = useRef<Fix | null>(null);
    const stationarySince = useRef<number | null>(null);
    const watchId = useRef<number | null>(null);
    const wakeLock = useRef<WakeLockSentinel | null>(null);

    /**
     * Stable identity, and the queue itself. A callback that changed on every
     * render would restart any effect depending on it, which is how a twenty
     * second flush timer becomes a flush on every keystroke.
     */
    const takeFixes = useCallback((): Fix[] => {
        const taken = pending.current;

        if (taken.length === 0) {
            return [];
        }

        pending.current = [];
        setState((s) => ({ ...s, pendingCount: 0 }));

        return taken;
    }, []);

    useEffect(() => {
        if (!active || !('geolocation' in navigator)) {
            return;
        }

        watchId.current = navigator.geolocation.watchPosition(
            (position) => {
                const fix: Fix = {
                    longitude: position.coords.longitude,
                    latitude: position.coords.latitude,
                    accuracy_m: position.coords.accuracy,
                    speed_mps: position.coords.speed,
                    heading: position.coords.heading,
                    altitude_m: position.coords.altitude,
                    recorded_at: new Date(position.timestamp).toISOString(),
                };

                // Drives the marker without re-rendering the tree.
                latest.current = fix;

                const previous = lastKept.current;
                const moved = previous === null ? Infinity : metresBetween(previous, fix);
                const sinceKept =
                    previous === null
                        ? Infinity
                        : new Date(fix.recorded_at).getTime() - new Date(previous.recorded_at).getTime();

                // Inside the accuracy radius is not movement, it is noise.
                const isStationary = moved < Math.max(10, fix.accuracy_m ?? 10);

                if (isStationary) {
                    stationarySince.current ??= Date.now();
                } else {
                    stationarySince.current = null;
                }

                const backedOff = stationarySince.current !== null && Date.now() - stationarySince.current > 60_000;
                const interval = backedOff ? 30_000 : 5_000;

                if (moved >= 10 || sinceKept >= interval) {
                    lastKept.current = fix;
                    pending.current = [...pending.current, fix];
                    setState((s) => ({
                        ...s,
                        current: fix,
                        pendingCount: pending.current.length,
                        track: [...s.track.slice(-200), [fix.longitude, fix.latitude]],
                        permission: 'granted',
                    }));
                } else {
                    setState((s) => (s.current === null ? { ...s, current: fix, permission: 'granted' } : s));
                }
            },
            (error) => {
                setState((s) => ({
                    ...s,
                    permission: error.code === error.PERMISSION_DENIED ? 'denied' : s.permission,
                    error:
                        error.code === error.PERMISSION_DENIED
                            ? 'Location is switched off for this app. Turn it on to record where you are working.'
                            : 'Could not get a position fix. Move into the open and try again.',
                }));
            },
            { enableHighAccuracy: true, maximumAge: 0, timeout: 20_000 },
        );

        return () => {
            if (watchId.current !== null) {
                navigator.geolocation.clearWatch(watchId.current);
                watchId.current = null;
            }
        };
    }, [active]);

    // The wake lock, held only while a session is open.
    useEffect(() => {
        if (!active) {
            return;
        }

        if (!('wakeLock' in navigator)) {
            return;
        }

        let cancelled = false;

        const acquire = async () => {
            try {
                const sentinel = await navigator.wakeLock.request('screen');

                if (cancelled) {
                    void sentinel.release();

                    return;
                }

                wakeLock.current = sentinel;
                setState((s) => ({ ...s, wakeLock: 'held' }));
            } catch {
                setState((s) => ({ ...s, wakeLock: 'denied' }));
            }
        };

        void acquire();

        // Android drops the lock when the app goes to the background.
        const reacquire = () => {
            if (document.visibilityState === 'visible' && wakeLock.current === null) {
                void acquire();
            }
        };

        document.addEventListener('visibilitychange', reacquire);

        return () => {
            cancelled = true;
            document.removeEventListener('visibilitychange', reacquire);
            void wakeLock.current?.release();
            wakeLock.current = null;
        };
    }, [active]);

    return { ...state, latest, takeFixes };
}
