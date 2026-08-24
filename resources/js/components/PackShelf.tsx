import { PackDownload } from '@/components/PackDownload';
import { usePack } from '@/lib/offline/usePack';

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
            <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
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
