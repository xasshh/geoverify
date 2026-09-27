import { FieldShell } from '@/components/FieldShell';
import { Composer, PinnedNote, Thread } from '@/components/FieldInbox';
import type { OfficerDay } from '@/lib/fieldDay';
import { useInbox } from '@/lib/offline/messages';

/**
 * The supervisor inbox, to the phone board: who you are talking to, the
 * pinned note, the thread with returned records as cards, quick replies.
 * Readable and writable with no signal; replies go when it comes back.
 */
export default function Inbox({ day }: { day: OfficerDay }) {
    const inbox = useInbox();
    const supervisor = day.supervisor;
    const name = supervisor?.name ?? 'Your supervisor';

    return (
        <FieldShell day={day} current="inbox" title="Supervisor inbox">
            <section className="mx-auto flex max-w-3xl flex-col overflow-hidden rounded-card border border-rule bg-surface">
                <header className="flex items-center gap-3 border-b border-rule bg-raised px-4 py-3">
                    <span className="flex size-11 items-center justify-center rounded-sm bg-ink font-extrabold text-inverse">
                        {name
                            .split(/\s+/)
                            .slice(0, 2)
                            .map((p) => p.charAt(0))
                            .join('')}
                    </span>
                    <span className="min-w-0 flex-1">
                        <span className="block text-body font-extrabold text-ink">{name}</span>
                        <span className="block text-table text-green">Supervisor{supervisor?.staffRef != null && ` · ${supervisor.staffRef}`}</span>
                    </span>
                    {supervisor?.phone != null && (
                        <a href={`tel:${supervisor.phone}`} aria-label={`Call ${name}`} className="flex size-11 items-center justify-center rounded-sm bg-gold-soft text-gold-dark">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
                                <path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2" />
                            </svg>
                        </a>
                    )}
                </header>
                <PinnedNote messages={inbox.messages} />
                <Thread messages={inbox.messages} className="h-[calc(100dvh-440px)] min-h-[240px] px-4 py-4 sm:h-[58dvh]" />
                <div className="border-t border-rule bg-raised px-4 py-4">
                    <Composer to={name} />
                    {!navigator.onLine && <p className="mt-2 text-table text-muted">No signal. Messages are kept on this device and sent when it comes back.</p>}
                </div>
            </section>
        </FieldShell>
    );
}
