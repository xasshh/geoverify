import { cx } from '@/lib/cx';

export type Connectivity = 'online' | 'offline' | 'syncing';

interface SyncIndicatorProps {
    connectivity: Connectivity;
    /** Mutations waiting on the device. Zero is worth saying out loud. */
    queued: number;
    /** Human phrasing, already formatted. Empty when nothing has synced yet. */
    lastSync: string | null;
    compact?: boolean;
}

/**
 * The most anxiety-producing question in the field app, answered before it is
 * asked: is my day's work safe.
 *
 * Offline is a normal state here, not an error, so it is never styled as one.
 * Amber is used only when work is genuinely waiting; an officer offline with an
 * empty queue has lost nothing and the interface says so plainly.
 */
export function SyncIndicator({
    connectivity,
    queued,
    lastSync,
    compact = false,
}: SyncIndicatorProps) {
    const hasBacklog = queued > 0;

    const tone = hasBacklog
        ? 'text-amber-ink'
        : connectivity === 'offline'
          ? 'text-graphite'
          : 'text-green';

    const queueLabel = hasBacklog
        ? `${String(queued)} queued`
        : connectivity === 'offline'
          ? 'Nothing waiting'
          : 'All saved';

    const connectivityLabel =
        connectivity === 'offline'
            ? 'Offline'
            : connectivity === 'syncing'
              ? 'Sending'
              : 'Online';

    return (
        <div
            className={cx('flex items-center gap-2.5', compact ? 'text-label' : 'text-ui')}
            role="status"
            aria-live="polite"
        >
            <span className={cx('flex items-center gap-1.5 font-semibold', tone)}>
                {connectivity === 'syncing' ? (
                    <svg
                        className="size-3 animate-spin motion-reduce:animate-none"
                        viewBox="0 0 12 12"
                        aria-hidden="true"
                    >
                        <path
                            d="M6 1a5 5 0 1 1-5 5"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                            strokeLinecap="round"
                        />
                    </svg>
                ) : (
                    <svg width="9" height="9" viewBox="0 0 10 10" aria-hidden="true">
                        {connectivity === 'offline' ? (
                            <circle
                                cx="5"
                                cy="5"
                                r="3.6"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="1.7"
                            />
                        ) : (
                            <circle cx="5" cy="5" r="4" fill="currentColor" />
                        )}
                    </svg>
                )}
                {connectivityLabel}
            </span>

            <span aria-hidden="true" className="text-rule-strong">
                /
            </span>

            <span className={cx('numeric-mono', hasBacklog ? 'text-amber-ink' : 'text-muted')}>
                {queueLabel}
            </span>

            {lastSync !== null && (
                <>
                    <span aria-hidden="true" className="text-rule-strong">
                        /
                    </span>
                    <span className="numeric-mono text-faint">synced {lastSync}</span>
                </>
            )}
        </div>
    );
}
