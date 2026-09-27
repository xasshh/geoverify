import { FieldShell } from '@/components/FieldShell';
import { PackShelf } from '@/components/PackShelf';
import { Button } from '@/components/Button';
import { ago, type OfficerDay } from '@/lib/fieldDay';
import { useInbox } from '@/lib/offline/messages';
import { useOfflineQueue } from '@/lib/offline/useOfflineQueue';

/**
 * Sync & device: what is waiting to leave this handset, the offline maps, and
 * a button to try now. Everything here reads the device, not the server.
 */
export default function Device({ day, mandates }: { day: OfficerDay; mandates: { coverageAreaId: number; mandate: string }[] }) {
    const queue = useOfflineQueue();
    const inbox = useInbox();
    const unsent = inbox.messages.filter((m) => m.pending === 1).length;

    return (
        <FieldShell day={day} current="device" title="Sync & device">
            <div className="grid max-w-4xl gap-4 sm:grid-cols-3">
                <section className="rounded-card border border-rule bg-raised px-5 py-5">
                    <p className="text-label font-extrabold tracking-[0.05em] text-muted uppercase">Connection</p>
                    <p className="mt-2 font-display text-display-s text-ink">{queue.online ? 'Online' : 'Offline'}</p>
                    <p className="mt-1 text-table text-muted">Last sync {ago(queue.lastSyncAt)}</p>
                </section>
                <section className="rounded-card border border-rule bg-raised px-5 py-5">
                    <p className="text-label font-extrabold tracking-[0.05em] text-muted uppercase">Waiting to send</p>
                    <p className="mt-2 font-display text-display-s text-ink">{queue.queued} records</p>
                    <p className="mt-1 text-table text-muted">
                        {queue.failed > 0 ? `${String(queue.failed)} refused by the server` : 'None refused'} · {unsent} {unsent === 1 ? 'message' : 'messages'}
                    </p>
                </section>
                <section className="flex flex-col justify-between rounded-card border border-rule bg-raised px-5 py-5">
                    <p className="text-ui text-muted">Everything captured is kept on this device until the server confirms it.</p>
                    <div className="mt-3">
                        <Button
                            variant="primary"
                            size="field"
                            busy={queue.syncing || inbox.syncing}
                            disabled={!queue.online}
                            onClick={() => {
                                void queue.syncNow();
                                void inbox.sync();
                            }}
                        >
                            Sync now
                        </Button>
                    </div>
                </section>
            </div>

            <section className="mt-7 max-w-4xl">
                <h2 className="font-display text-display-s text-ink">Offline maps</h2>
                <div className="mt-3 overflow-hidden rounded-card border border-rule bg-raised">
                    <PackShelf mandates={mandates} />
                </div>
            </section>
        </FieldShell>
    );
}
