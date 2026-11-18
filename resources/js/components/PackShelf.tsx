import { PackDownload } from '@/components/PackDownload';
import { usePack } from '@/lib/offline/usePack';
import { useImagery } from '@/lib/offline/useImagery';
import { useStreets } from '@/lib/offline/useStreets';

interface PackShelfProps {
    /** One per mandate the officer is working. Deduped by the caller. */
    mandates: Array<{ coverageAreaId: number; mandate: string }>;
}

/**
 * Where an officer gets their maps before they leave.
 *
 * On the board rather than on the capture screen, because the moment to spend
 * 67 MB is while there is wifi and before the day starts, not standing in a
 * compound in Wuse discovering the map is not there.
 */
export function PackShelf({ mandates }: PackShelfProps) {
    if (mandates.length === 0) {
        return null;
    }

    return (
        <section className="flex flex-col gap-3 px-4 py-3">
            {mandates.map((mandate) => (
                <PackRow key={mandate.coverageAreaId} {...mandate} />
            ))}
            {mandates.map((mandate) => (
                <StreetsRow key={`streets-${String(mandate.coverageAreaId)}`} {...mandate} />
            ))}
            {mandates.map((mandate) => (
                <ImageryRow key={`imagery-${String(mandate.coverageAreaId)}`} {...mandate} />
            ))}
        </section>
    );
}

function PackRow({ coverageAreaId, mandate }: { coverageAreaId: number; mandate: string }) {
    const pack = usePack(coverageAreaId);

    if (pack.state === 'installed') {
        // Nothing to say. A map that is here is not news, and a permanent green
        // tick is one more thing to read past every morning.
        return null;
    }

    return (
        <div className="flex flex-col gap-2">
            <p className="text-label font-semibold tracking-[0.05em] text-gold uppercase">
                {mandate}
            </p>
            <PackDownload
                state={pack.state}
                offered={pack.offered}
                received={pack.received}
                bytes={pack.bytes}
                error={pack.error}
                onDownload={pack.download}
                onCancel={pack.cancel}
            />
        </div>
    );
}

/**
 * Satellite imagery for one mandate, offered only where it exists.
 *
 * Quiet unless there is something to do: no row for a mandate with no imagery,
 * none once it is on the phone.
 */
function ImageryRow({ coverageAreaId, mandate }: { coverageAreaId: number; mandate: string }) {
    const imagery = useImagery(coverageAreaId);

    if (imagery.state === 'checking' || imagery.state === 'absent' || imagery.state === 'installed') {
        return null;
    }

    const megabytes = imagery.offered?.megabytes ?? 0;

    return (
        <div className="flex flex-col gap-2 rounded-card border border-rule bg-raised p-3">
            <p className="text-label font-semibold tracking-[0.05em] text-gold uppercase">{mandate}: satellite view</p>
            <p className="text-ui text-muted">
                {imagery.state === 'stale' ? 'A newer image is available. ' : ''}
                {imagery.offered?.captured !== null && imagery.offered?.captured !== undefined
                    ? `Image of ${imagery.offered.captured}, `
                    : ''}
                {megabytes.toFixed(1)} MB. Optional: the street map works without it.
            </p>
            {imagery.state === 'downloading' ? (
                <div className="flex items-center gap-3">
                    <progress
                        className="h-2 w-full accent-gold"
                        value={imagery.received}
                        max={imagery.offered?.bytes ?? 1}
                    />
                    <button type="button" onClick={imagery.cancel} className="text-label text-gold underline underline-offset-2">
                        Stop
                    </button>
                </div>
            ) : (
                <button
                    type="button"
                    onClick={imagery.download}
                    className="min-h-touch self-start rounded-sm border border-rule-strong px-4 text-ui font-semibold text-ink"
                >
                    {imagery.state === 'error' ? 'Try again' : 'Download satellite view'}
                </button>
            )}
            {imagery.error !== null && <p className="text-label text-alert">{imagery.error}</p>}
        </div>
    );
}

/**
 * The street map for one mandate. It downloads by itself, so this only speaks
 * while it is arriving or if it failed; once it is on the phone there is
 * nothing to say.
 */
function StreetsRow({ coverageAreaId, mandate }: { coverageAreaId: number; mandate: string }) {
    const streets = useStreets(coverageAreaId);

    if (streets.state !== 'downloading' && streets.state !== 'error') {
        return null;
    }

    return (
        <div className="flex flex-col gap-2 rounded-card border border-rule bg-raised p-3">
            <p className="text-label font-semibold tracking-[0.05em] text-gold uppercase">{mandate}: street names</p>
            {streets.state === 'downloading' ? (
                <>
                    <p className="text-ui text-muted">
                        Getting street names and landmarks, {(streets.offered?.megabytes ?? 0).toFixed(1)} MB.
                    </p>
                    <progress className="h-2 w-full accent-gold" value={streets.received} max={streets.offered?.bytes ?? 1} />
                </>
            ) : (
                <>
                    <p className="text-label text-alert">{streets.error}</p>
                    <button
                        type="button"
                        onClick={streets.retry}
                        className="min-h-touch self-start rounded-sm border border-rule-strong px-4 text-ui font-semibold text-ink"
                    >
                        Try again
                    </button>
                </>
            )}
        </div>
    );
}
