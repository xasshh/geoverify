import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/Button';
import { EnumerateShell } from '@/components/EnumerateShell';
import { Messages, TicketItem } from '@/components/SupportThread';
import { TICKET_STATUS, type Thread, type TicketRow } from '@/lib/support';
import { cx } from '@/lib/cx';
import type { EnumerateFrame } from '@/lib/enumerate';

interface Props {
    frame: EnumerateFrame;
    tickets: TicketRow[];
    thread: Thread | null;
    requests: { reference: string; business: string; tier: number }[];
    start: string | null;
    categories: Record<string, string>;
}

type Filter = 'all' | 'open' | 'resolved';

/**
 * Support and complaints, to board 32: the threads on the left, one open on
 * the right, and a new complaint that can be tied to a verification so the
 * desk sees what it is about without asking.
 */
export default function Support({ frame, tickets, thread, requests, start, categories }: Props) {
    const [composing, setComposing] = useState(start !== null || (thread === null && tickets.length === 0));
    const [filter, setFilter] = useState<Filter>('all');
    const [search, setSearch] = useState('');
    const create = useForm({ request: start ?? '', category: start !== null ? 'report_quality' : 'general', subject: '', body: '' });
    const reply = useForm({ body: '' });

    const q = search.trim().toLowerCase();
    const shown = tickets.filter(
        (t) =>
            (filter === 'all' || (filter === 'resolved' ? t.status === 'resolved' : t.status !== 'resolved')) &&
            (q === '' || [t.reference, t.subject, t.requestRef ?? ''].some((v) => v.toLowerCase().includes(q))),
    );

    return (
        <EnumerateShell
            current="support"
            frame={frame}
            title="Support & complaints"
            crumbs="We reply within one working day"
            actions={
                <Button variant="primary" size="field" onClick={() => { setComposing(true); }}>
                    + New complaint
                </Button>
            }
        >
            <Head title="Support & complaints" />

            <div className="grid gap-5 lg:grid-cols-[340px_minmax(0,1fr)] lg:items-start">
                <aside className="overflow-hidden rounded-card border border-rule bg-raised">
                    <div className="flex flex-col gap-3 border-b border-rule px-4 py-4">
                        <input
                            value={search}
                            onChange={(e) => { setSearch(e.target.value); }}
                            placeholder="Search ticket or verification ref"
                            aria-label="Search complaints"
                            className="h-11 rounded-sm border border-rule-strong bg-raised px-3.5 text-ui text-ink focus:border-gold focus:outline-none"
                        />
                        <div className="flex gap-2">
                            {(
                                [
                                    ['all', 'All', tickets.length],
                                    ['open', 'Open', tickets.filter((t) => t.status !== 'resolved').length],
                                    ['resolved', 'Resolved', tickets.filter((t) => t.status === 'resolved').length],
                                ] as const
                            ).map(([key, label, count]) => (
                                <button
                                    key={key}
                                    type="button"
                                    aria-pressed={filter === key}
                                    onClick={() => { setFilter(key); }}
                                    className={cx('min-h-[34px] rounded-full border px-3 text-table font-bold', filter === key ? 'border-ink bg-ink text-inverse' : 'border-rule-strong text-ink')}
                                >
                                    {label} · {count}
                                </button>
                            ))}
                        </div>
                    </div>
                    {shown.length === 0 ? (
                        <p className="px-4 py-8 text-center text-ui text-muted">No complaints here.</p>
                    ) : (
                        shown.map((t) => <TicketItem key={t.reference} ticket={t} active={thread?.reference === t.reference && !composing} href={`/enumerate/support?ticket=${t.reference}`} />)
                    )}
                </aside>

                {composing ? (
                    <section className="rounded-card border border-rule bg-raised px-6 py-6">
                        <h2 className="text-body font-extrabold text-ink">New complaint</h2>
                        <form
                            className="mt-4 flex flex-col gap-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                create.post('/enumerate/support');
                            }}
                        >
                            <label className="flex flex-col gap-1.5 text-table font-bold text-ink">
                                About which verification
                                <select
                                    value={create.data.request}
                                    onChange={(e) => { create.setData('request', e.target.value); }}
                                    className="h-11 rounded-sm border border-rule-strong bg-raised px-3 text-ui font-normal"
                                >
                                    <option value="">None, a general question</option>
                                    {requests.map((r) => (
                                        <option key={r.reference} value={r.reference}>
                                            {r.reference} · {r.business} (Tier {r.tier})
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex flex-col gap-1.5 text-table font-bold text-ink">
                                What it is about
                                <select
                                    value={create.data.category}
                                    onChange={(e) => { create.setData('category', e.target.value); }}
                                    className="h-11 rounded-sm border border-rule-strong bg-raised px-3 text-ui font-normal"
                                >
                                    {Object.entries(categories).map(([key, label]) => (
                                        <option key={key} value={key}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex flex-col gap-1.5 text-table font-bold text-ink">
                                Title
                                <input
                                    value={create.data.subject}
                                    maxLength={160}
                                    onChange={(e) => { create.setData('subject', e.target.value); }}
                                    placeholder="e.g. Photos don’t show the storefront clearly"
                                    className="h-11 rounded-sm border border-rule-strong bg-raised px-3 text-ui font-normal"
                                />
                            </label>
                            <label className="flex flex-col gap-1.5 text-table font-bold text-ink">
                                What went wrong
                                <textarea
                                    rows={5}
                                    maxLength={4000}
                                    value={create.data.body}
                                    onChange={(e) => { create.setData('body', e.target.value); }}
                                    className="rounded-sm border border-rule-strong bg-raised p-3 text-ui font-normal"
                                />
                            </label>
                            {create.errors.body !== undefined && <p role="alert" className="text-ui font-semibold text-alert-ink">{create.errors.body}</p>}
                            <div className="flex gap-2">
                                <Button type="submit" variant="primary" size="field" busy={create.processing}>
                                    Send complaint
                                </Button>
                                {tickets.length > 0 && (
                                    <Button variant="quiet" size="field" onClick={() => { setComposing(false); }}>
                                        Cancel
                                    </Button>
                                )}
                            </div>
                        </form>
                    </section>
                ) : thread === null ? (
                    <p className="max-w-none rounded-card border border-rule bg-raised px-6 py-12 text-center text-ui text-muted">Choose a complaint to read it.</p>
                ) : (
                    <section className="flex flex-col overflow-hidden rounded-card border border-rule bg-raised">
                        <div className="flex flex-wrap items-start justify-between gap-3 border-b border-rule px-6 py-5">
                            <div>
                                <p className="flex items-center gap-2">
                                    <span className="font-mono text-table text-muted">{thread.reference}</span>
                                    <span className={cx('rounded-full px-2 py-0.5 text-[0.6875rem] font-extrabold', TICKET_STATUS[thread.status].className)}>
                                        {TICKET_STATUS[thread.status].label}
                                    </span>
                                </p>
                                <h2 className="mt-1 text-[1.25rem] font-extrabold text-ink">{thread.subject}</h2>
                                {thread.requestRef !== null && (
                                    <Link href={`/enumerate/verifications/${thread.requestRef}`} className="mt-2 inline-flex rounded-sm bg-sunken px-3 py-1.5 text-table font-bold text-ink hover:bg-gold-soft">
                                        {thread.tier !== null && <span className="mr-2 text-gold-dark">TIER {thread.tier}</span>}
                                        {thread.requestRef} · {thread.business} →
                                    </Link>
                                )}
                            </div>
                            <p className="text-right text-table text-muted">
                                Category: <span className="font-bold text-ink">{thread.category}</span>
                            </p>
                        </div>
                        <div className="bg-surface px-6 py-6">
                            <Messages thread={thread} />
                        </div>
                        <form
                            className="flex gap-2 border-t border-rule px-4 py-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                reply.post(`/enumerate/support/${thread.reference}/reply`, { preserveScroll: true, onSuccess: () => { reply.reset(); } });
                            }}
                        >
                            <input
                                value={reply.data.body}
                                onChange={(e) => { reply.setData('body', e.target.value); }}
                                placeholder={thread.status === 'resolved' ? 'Write to reopen this complaint…' : 'Write a reply…'}
                                aria-label="Reply"
                                className="h-11 min-w-0 flex-1 rounded-sm border border-rule-strong bg-raised px-3.5 text-ui text-ink focus:border-gold focus:outline-none"
                            />
                            <Button type="submit" variant="primary" size="field" busy={reply.processing} disabled={reply.data.body.trim() === ''}>
                                Send
                            </Button>
                        </form>
                    </section>
                )}
            </div>
        </EnumerateShell>
    );
}
