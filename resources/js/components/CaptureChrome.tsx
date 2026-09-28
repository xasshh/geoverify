import { cx } from '@/lib/cx';
import { shortCell } from '@/lib/fieldDay';
import { useInbox } from '@/lib/offline/messages';

/**
 * The top of a capture step, to the mockup: close, the title, which step of
 * four, and a progress rule. Step 1 is the map, which keeps its own chrome.
 */
export function CaptureStepHeader({ step, title, subtitle, onClose }: { step: 2 | 3 | 4; title: string; subtitle: string; onClose: () => void }) {
    return (
        <header className="shrink-0 border-b border-rule bg-raised">
            <div className="flex items-center gap-3 px-4 py-3">
                <button type="button" onClick={onClose} aria-label="Back" className="flex size-11 items-center justify-center rounded-sm text-ink hover:bg-sunken">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
                        <path d="M6 6l12 12M18 6 6 18" />
                    </svg>
                </button>
                <div className="min-w-0 flex-1">
                    <p className="font-display text-display-s text-ink">{title}</p>
                    <p className="truncate text-table text-muted">{subtitle}</p>
                </div>
                <p className="shrink-0 text-ui font-extrabold text-gold-dark">Step {step} of 4</p>
            </div>
            <div className="h-1 bg-sunken" aria-hidden="true">
                <div className="h-full bg-gold" style={{ width: `${String((step / 4) * 100)}%` }} />
            </div>
        </header>
    );
}

/** "GPS locked · ±3 m": the fix the record will carry, and whether it is good enough. */
export function GpsCard({ position, cellH3 = null }: { position: { latitude: number; longitude: number; accuracy_m: number | null } | null; cellH3?: string | null }) {
    const accuracy = position?.accuracy_m ?? null;
    const good = accuracy !== null && accuracy <= 10;

    return (
        <div className="flex items-center gap-3 rounded-card bg-ink px-4 py-3.5 text-inverse">
            <span className={cx('flex size-10 shrink-0 items-center justify-center rounded-sm', good ? 'bg-gold/30 text-gold-soft' : 'bg-amber/30 text-amber-soft')} aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                    <path d="M12 2v4M12 18v4M2 12h4M18 12h4M12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8z" />
                </svg>
            </span>
            <span className="min-w-0 flex-1">
                <span className="block text-ui font-extrabold">
                    {position === null ? 'Waiting for GPS' : good ? 'GPS locked' : 'GPS weak'}
                    {accuracy !== null && ` · ±${String(Math.round(accuracy))} m`}
                </span>
                <span className="block truncate numeric-mono text-table opacity-80">
                    {position === null ? 'Stand still in the open' : `${position.latitude.toFixed(4)}, ${position.longitude.toFixed(4)}`}
                    {cellH3 !== null && ` · cell ${shortCell(cellH3)}`}
                </span>
            </span>
            {position !== null && cellH3 !== null && <span className="shrink-0 rounded-full bg-gold px-2.5 py-1 text-table font-extrabold text-on-accent">In your cell</span>}
        </div>
    );
}

/** The supervisor's pinned note, where the officer is filling the form. */
export function SupervisorNote() {
    const { messages } = useInbox();
    const pinned = [...messages].reverse().find((m) => m.pinned);

    if (pinned === undefined) {
        return null;
    }

    return (
        <p className="rounded-card border border-amber/30 bg-amber-soft px-4 py-3 text-ui text-ink max-w-none">
            <span className="font-extrabold text-amber-ink">Supervisor note: </span>
            {pinned.body}
        </p>
    );
}
