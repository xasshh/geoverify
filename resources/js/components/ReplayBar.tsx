import { REPLAY_SPEEDS, replaySpan, type ReplayState } from '@/components/LiveMap';
import { cx } from '@/lib/cx';

/**
 * The controls for replaying an officer's day over the live map: play and
 * pause, a slider across the whole session, the speed, and the clock.
 *
 * Shown only while a replay is loaded (or failed to load). Sits at the foot
 * of the map, inside its relative wrapper.
 */

interface Controls {
    state: ReplayState | null;
    error: string | null;
    stop: () => void;
    toggle: () => void;
    seek: (t: number) => void;
    speed: (speed: ReplayState['speed']) => void;
}

function clock(epochSeconds: number): string {
    return new Date(epochSeconds * 1000).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}

export function ReplayBar({ controls }: { controls: Controls }) {
    const { state, error } = controls;

    if (state === null && error === null) {
        return null;
    }

    if (state === null) {
        return (
            <div className="absolute right-14 bottom-9 left-60 z-10 flex items-center justify-between gap-3 rounded-card border border-rule bg-raised/95 px-4 py-2.5 shadow-float">
                <p className="text-ui text-muted">{error}</p>
                <button type="button" onClick={controls.stop} className="text-table font-bold text-ink underline">
                    Dismiss
                </button>
            </div>
        );
    }

    const [start, end] = replaySpan(state.replay);
    const shown = state.replay.captures.filter((c) => c[2] <= state.t).length;

    return (
        <div
            className="absolute right-14 bottom-9 left-60 z-10 flex flex-col gap-2 rounded-card border border-rule bg-raised/95 px-4 py-3 shadow-float"
            role="group"
            aria-label={`Replay of ${state.replay.officer}'s day`}
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-ui font-bold text-ink">
                    Replaying {state.replay.officer}
                    <span className="ml-2 numeric-mono text-table font-normal text-muted">
                        {clock(state.t)} · {shown} of {state.replay.captures.length} captures
                    </span>
                </p>
                <div className="flex items-center gap-1.5">
                    {REPLAY_SPEEDS.map((speed) => (
                        <button
                            key={speed}
                            type="button"
                            onClick={() => {
                                controls.speed(speed);
                            }}
                            aria-pressed={state.speed === speed}
                            title={`${String(speed)} minute${speed === 1 ? '' : 's'} of the day per second`}
                            className={cx(
                                'rounded-sm border px-2 py-0.5 numeric-mono text-label',
                                state.speed === speed ? 'border-gold text-ink' : 'border-rule text-muted hover:border-rule-strong',
                            )}
                        >
                            {speed}x
                        </button>
                    ))}
                    <button
                        type="button"
                        onClick={controls.stop}
                        className="ml-1 rounded-sm border border-rule px-2 py-0.5 text-label text-muted hover:border-rule-strong"
                    >
                        Close
                    </button>
                </div>
            </div>

            <div className="flex items-center gap-3">
                <button
                    type="button"
                    onClick={controls.toggle}
                    aria-label={state.playing ? 'Pause' : 'Play'}
                    className="flex size-9 shrink-0 items-center justify-center rounded-full bg-gold text-on-accent"
                >
                    {state.playing ? (
                        <svg viewBox="0 0 16 16" className="size-4" aria-hidden>
                            <rect x="3" y="2" width="3.5" height="12" fill="currentColor" />
                            <rect x="9.5" y="2" width="3.5" height="12" fill="currentColor" />
                        </svg>
                    ) : (
                        <svg viewBox="0 0 16 16" className="size-4" aria-hidden>
                            <path d="M4 2 L14 8 L4 14 Z" fill="currentColor" />
                        </svg>
                    )}
                </button>
                <span className="numeric-mono text-label text-faint">{clock(start)}</span>
                <input
                    type="range"
                    min={start}
                    max={end}
                    step={1}
                    value={Math.round(state.t)}
                    onChange={(e) => {
                        controls.seek(Number(e.target.value));
                    }}
                    aria-label="Time of day"
                    className="w-full accent-gold"
                />
                <span className="numeric-mono text-label text-faint">{clock(end)}</span>
            </div>
        </div>
    );
}
