import { Head, Link, router, useForm } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { ConsoleShell } from '@/components/ConsoleShell';
import { cx } from '@/lib/cx';
import { ago, clock } from '@/lib/fieldDay';

interface Officer {
    id: number;
    name: string;
    staffRef: string | null;
    unread: number;
    last: string | null;
    lastAt: string | null;
}

interface Message {
    id: number;
    direction: 'to_officer' | 'from_officer';
    kind: 'text' | 'returned_record' | 'cell_assigned' | 'broadcast';
    body: string;
    sender: string | null;
    sentAt: string;
    pinned: boolean;
    read: boolean;
    record: { id: number; ref: string } | null;
}

const KIND: Record<Message['kind'], string> = {
    text: '',
    returned_record: 'Returned record',
    cell_assigned: 'Cell assigned',
    broadcast: 'Team broadcast',
};

/** Messages: every officer on the team on the left, the thread with one on the right. */
export default function Messages({
    officers,
    selected,
    thread,
}: {
    officers: Officer[];
    selected: { id: number; name: string; staffRef: string | null; phone: string | null } | null;
    thread: Message[];
}) {
    const form = useForm({ body: '' });

    return (
        <ConsoleShell current="messages">
            <Head title="Messages" />
            <div className="mx-auto max-w-[1200px] px-6 pb-20">
                <header className="mt-8 border-b border-rule pb-3">
                    <h1 className="font-display text-display-l text-ink">Messages</h1>
                    <p className="mt-1 text-ui text-muted">What your officers said, and what you told them. Returned records and broadcasts appear in their threads.</p>
                </header>

                <div className="mt-6 grid min-h-[560px] gap-5 lg:grid-cols-[300px_minmax(0,1fr)]">
                    <nav className="flex flex-col divide-y divide-rule overflow-hidden rounded-card border border-rule bg-raised" aria-label="Officers">
                        {officers.length === 0 && <p className="px-5 py-6 text-ui text-muted">Nobody holds cells you assigned yet.</p>}
                        {officers.map((o) => (
                            <Link
                                key={o.id}
                                href={`/console/messages/${String(o.id)}`}
                                preserveScroll
                                className={cx('flex flex-col px-4 py-3', selected?.id === o.id ? 'bg-gold-soft' : 'hover:bg-sunken')}
                            >
                                <span className="flex items-center justify-between gap-2">
                                    <span className="truncate font-bold text-ink">{o.name}</span>
                                    {o.unread > 0 && <span className="rounded-full bg-alert px-2 text-table font-extrabold text-on-accent">{o.unread}</span>}
                                </span>
                                <span className="truncate text-table text-muted">{o.last ?? 'No messages yet'}</span>
                                {o.lastAt !== null && <span className="text-[11px] text-faint">{ago(o.lastAt)}</span>}
                            </Link>
                        ))}
                    </nav>

                    {selected === null ? (
                        <p className="rounded-card border border-rule bg-raised px-6 py-10 text-center text-ui text-muted max-w-none">Choose an officer.</p>
                    ) : (
                        <section className="flex flex-col overflow-hidden rounded-card border border-rule bg-surface">
                            <header className="flex items-center justify-between gap-3 border-b border-rule bg-raised px-5 py-3">
                                <span>
                                    <span className="block text-body font-extrabold text-ink">{selected.name}</span>
                                    <span className="block numeric-mono text-table text-muted">{selected.staffRef ?? 'Field officer'}</span>
                                </span>
                                {selected.phone !== null && (
                                    <a href={`tel:${selected.phone}`} className="rounded-sm border border-rule-strong px-3 py-2 text-ui font-bold text-ink">
                                        Call
                                    </a>
                                )}
                            </header>
                            <div className="flex max-h-[520px] flex-1 flex-col gap-3 overflow-y-auto px-5 py-5">
                                {thread.length === 0 && <p className="text-ui text-muted">Nothing yet.</p>}
                                {thread.map((m) => {
                                    const mine = m.direction === 'to_officer';

                                    return (
                                        <div key={m.id} className={cx('flex', mine ? 'justify-end' : 'justify-start')}>
                                            <div className={cx('max-w-[80%] rounded-card px-4 py-2.5 text-ui', mine ? (m.kind === 'returned_record' ? 'bg-alert-soft text-ink' : 'bg-gold text-on-accent') : 'bg-raised text-ink shadow-card')}>
                                                {KIND[m.kind] !== '' && <p className="text-label font-extrabold tracking-[0.05em] uppercase opacity-80">{KIND[m.kind]}</p>}
                                                <p className="whitespace-pre-wrap">
                                                    {m.record !== null && <span className="font-bold">{m.record.ref} </span>}
                                                    {m.body}
                                                </p>
                                                <p className="mt-1 flex items-center gap-3 text-table opacity-80">
                                                    {clock(m.sentAt)}
                                                    {mine && (m.read ? ' · Read' : ' · Delivered')}
                                                    {mine && (
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                router.post(`/console/field-messages/${String(m.id)}/pin`, {}, { preserveScroll: true });
                                                            }}
                                                            className="font-bold underline underline-offset-2"
                                                        >
                                                            {m.pinned ? 'Unpin' : 'Pin'}
                                                        </button>
                                                    )}
                                                </p>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                            <form
                                className="flex gap-2 border-t border-rule bg-raised px-5 py-4"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    form.post(`/console/messages/${String(selected.id)}`, { preserveScroll: true, onSuccess: () => { form.reset(); } });
                                }}
                            >
                                <input
                                    value={form.data.body}
                                    onChange={(e) => {
                                        form.setData('body', e.target.value);
                                    }}
                                    maxLength={1000}
                                    placeholder={`Message ${selected.name}…`}
                                    aria-label={`Message ${selected.name}`}
                                    className="h-11 min-w-0 flex-1 rounded-sm border border-rule-strong bg-raised px-4 text-ui text-ink"
                                />
                                <Button type="submit" variant="primary" size="console" busy={form.processing} disabled={form.data.body.trim() === ''}>
                                    Send
                                </Button>
                            </form>
                            {form.errors.body !== undefined && <p className="px-5 pb-3 text-ui text-alert">{form.errors.body}</p>}
                        </section>
                    )}
                </div>
            </div>
        </ConsoleShell>
    );
}
