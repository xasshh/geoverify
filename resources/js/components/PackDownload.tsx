import { Button } from '@/components/Button';
import { cx } from '@/lib/cx';
import type { PackMeta } from '@/lib/offline/pack';
import type { PackState } from '@/lib/offline/usePack';

interface PackDownloadProps {
    state: PackState;
    offered: PackMeta | null;
    received: number;
    bytes: number;
    error: string | null;
    onDownload: () => void;
    onCancel: () => void;
    /** Set on the capture screen, where having no map blocks the work. */
    blocking?: boolean;
}

function megabytes(value: number): string {
    return `${(value / 1_048_576).toFixed(1)} MB`;
}

/**
 * Roughly how long this will take, said the way a person would say it.
 *
 * 2 Mbps is a working assumption, not a promise, so the wording is deliberately
 * approximate. An officer needs to know whether this is a cup of tea or a
 * moment, not a countdown that will be wrong.
 */
function duration(seconds: number): string {
    if (seconds < 90) {
        return 'about a minute';
    }

    return `about ${String(Math.round(seconds / 60))} minutes`;
}

/**
 * The download an officer makes before they leave for the field.
 *
 * Size first, then the button. The cost is stated before it is incurred, and the
 * progress afterwards counts bytes actually written to the device rather than
 * anything optimistic.
 */
export function PackDownload({
    state,
    offered,
    received,
    bytes,
    error,
    onDownload,
    onCancel,
    blocking = false,
}: PackDownloadProps) {
    if (state === 'checking' || state === 'installed') {
        return null;
    }

    const pct = bytes === 0 ? 0 : Math.min(100, (received / bytes) * 100);

    return (
        <div
            className={cx(
                'flex flex-col gap-3 rounded-sm border border-rule-strong bg-raised p-4',
                blocking && 'mx-4',
            )}
        >
            {state === 'absent' && (
                <div>
                    <p className="text-body text-ink">No map on this device</p>
                    <p className="mt-1 text-ui text-muted">
                        The map is downloaded before you go out. Connect to wifi and open this
                        screen again.
                    </p>
                </div>
            )}

            {(state === 'available' || state === 'stale' || state === 'error') &&
                offered !== null && (
                    <>
                        <div>
                            <p className="text-body text-ink">
                                {state === 'stale' ? 'A newer map is ready' : 'Map for '}
                                {state === 'stale' ? '' : offered.mandate}
                            </p>
                            <p className="mt-1 flex flex-wrap items-baseline gap-x-2 text-ui text-muted">
                                <span className="numeric-mono text-ink">
                                    {offered.megabytes.toFixed(1)} MB
                                </span>
                                <span>on wifi, {duration(offered.secondsAt2Mbps)}</span>
                            </p>
                            {received > 0 && received < bytes && (
                                <p className="mt-1 text-ui text-muted">
                                    {megabytes(received)} is already here and will not be fetched
                                    again.
                                </p>
                            )}
                            <p className="mt-2 text-label text-faint">
                                {Object.entries(offered.layers)
                                    .filter(([, count]) => count > 0)
                                    .map(([layer, count]) => `${count.toLocaleString()} ${layer}`)
                                    .join(', ')}
                            </p>
                        </div>

                        {error !== null && <p className="text-ui text-alert">{error}</p>}

                        <Button variant="primary" size="field" fullWidth onClick={onDownload}>
                            {state === 'error' ? 'Try again' : 'Download the map'}
                        </Button>
                    </>
                )}

            {state === 'downloading' && (
                <>
                    <div className="flex items-baseline justify-between numeric-mono text-ui">
                        <span className="text-ink">
                            {megabytes(received)} of {megabytes(bytes)}
                        </span>
                        <span className="text-muted">{pct.toFixed(0)}%</span>
                    </div>
                    <div
                        className="h-1.5 w-full overflow-hidden rounded-full bg-sunken"
                        role="progressbar"
                        aria-valuenow={Math.round(pct)}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-label="Map download"
                    >
                        <div
                            className="h-full rounded-full bg-gold transition-[width] duration-300 motion-reduce:transition-none"
                            style={{ width: `${String(pct)}%` }}
                        />
                    </div>
                    <p className="text-label text-faint">
                        You can leave this screen. Stopping keeps what has arrived.
                    </p>
                    <Button variant="quiet" size="field-compact" onClick={onCancel}>
                        Stop
                    </Button>
                </>
            )}
        </div>
    );
}
