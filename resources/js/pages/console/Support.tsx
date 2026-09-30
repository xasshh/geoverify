import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/Button';
import { ConsoleShell } from '@/components/ConsoleShell';
import { Messages, TicketItem } from '@/components/SupportThread';
import { TICKET_STATUS, type Thread, type TicketRow } from '@/lib/support';
import { cx } from '@/lib/cx';
import { kobo } from '@/lib/enumerate';

interface Props {
    tickets: TicketRow[];
    thread: (Thread & { refundableMinor: number | null }) | null;
    canRefund: boolean;
}

/**
 * The Enumerate support desk. Waiting threads first. A supervisor answers and
 * can arrange another visit by saying so; an administrator can also refund to
 * the wallet, up to what has not already gone back.
 */
export default function Support({ tickets, thread, canRefund }: Props) {
    const page = usePage();
    const errors = page.props.errors as Record<string, string | undefined>;
    const [body, setBody] = useState('');
    const [amount, setAmount] = useState('');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState<string | null>(null);

    const answer = (resolve: boolean) => {
        if (thread === null) {
            return;
        }

        setBusy(resolve ? 'resolve' : 'answer');
        router.post(`/console/support/${thread.reference}/answer`, { body, resolve }, {
            preserveScroll: true,
            onFinish: () => { setBusy(null); },
            onSuccess: () => { setBody(''); },
        });
    };

    return (
        <ConsoleShell current="support">
            <Head title="Support" />
            <div className="mx-auto max-w-[1320px] px-6 pb-20">
                <header className="mt-8 border-b border-rule pb-3">
                    <h1 className="font-display text-display-l text-ink">Support</h1>
                    <p className="mt-1 text-ui text-muted">Enumerate complaints and questions, waiting ones first. Requesters are promised a reply within one working day.</p>
                </header>

                {page.props.flash.status !== null && <p className="mt-5 max-w-none rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green">{page.props.flash.status}</p>}

                <div className="mt-6 grid gap-5 lg:grid-cols-[340px_minmax(0,1fr)] lg:items-start">
                    <aside className="overflow-hidden rounded-card border border-rule bg-raised">
                        {tickets.length === 0 ? (
                            <p className="px-4 py-8 text-center text-ui text-muted">No complaints.</p>
                        ) : (
                            tickets.map((t) => <TicketItem key={t.reference} ticket={t} active={thread?.reference === t.reference} href={`/console/support?ticket=${t.reference}`} />)
                        )}
                    </aside>

                    {thread === null ? (
                        <p className="max-w-none rounded-card border border-rule bg-raised px-6 py-12 text-center text-ui text-muted">Nothing is waiting.</p>
                    ) : (
                        <section className="overflow-hidden rounded-card border border-rule bg-raised">
                            <div className="border-b border-rule px-6 py-5">
                                <p className="flex items-center gap-2">
                                    <span className="font-mono text-table text-muted">{thread.reference}</span>
                                    <span className={cx('rounded-full px-2 py-0.5 text-[0.6875rem] font-extrabold', TICKET_STATUS[thread.status].className)}>
                                        {TICKET_STATUS[thread.status].label}
                                    </span>
                                    <span className="text-table text-muted">· {thread.category}</span>
                                </p>
                                <h2 className="mt-1 text-[1.25rem] font-extrabold text-ink">{thread.subject}</h2>
                                {thread.requestRef !== null && (
                                    <p className="mt-1 text-table text-muted">
                                        About {thread.requestRef} · {thread.business}
                                        {thread.tier !== null && ` · Tier ${String(thread.tier)}`}
                                    </p>
                                )}
                            </div>
                            <div className="bg-surface px-6 py-6">
                                <Messages thread={thread} />
                            </div>
                            <div className="border-t border-rule px-6 py-5">
                                <textarea
                                    rows={3}
                                    value={body}
                                    maxLength={4000}
                                    onChange={(e) => { setBody(e.target.value); }}
                                    placeholder="Your reply. The requester sees your first name and “Enumerate support”."
                                    aria-label="Reply"
                                    className="w-full rounded-sm border border-rule-strong bg-raised p-3 text-ui text-ink focus:border-gold focus:outline-none"
                                />
                                {errors.body !== undefined && <p role="alert" className="mt-2 text-ui font-semibold text-alert-ink">{errors.body}</p>}
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <Button variant="primary" busy={busy === 'answer'} disabled={body.trim() === ''} onClick={() => { answer(false); }}>
                                        Reply
                                    </Button>
                                    <Button busy={busy === 'resolve'} disabled={body.trim() === ''} onClick={() => { answer(true); }}>
                                        Reply and resolve
                                    </Button>
                                </div>
                            </div>

                            {canRefund && thread.refundableMinor !== null && (
                                <div className="border-t border-rule bg-sunken px-6 py-5">
                                    <h3 className="text-ui font-extrabold text-ink">Refund to the wallet</h3>
                                    <p className="text-table text-muted">Up to {kobo(thread.refundableMinor)}, the part of the price not already returned. Posted to the ledger and written into this thread.</p>
                                    <div className="mt-3 flex flex-col gap-2 sm:flex-row">
                                        <input
                                            inputMode="decimal"
                                            value={amount}
                                            onChange={(e) => { setAmount(e.target.value.replace(/[^\d.]/g, '')); }}
                                            placeholder="Amount (₦)"
                                            aria-label="Refund amount"
                                            className="h-9 rounded-sm border border-rule-strong bg-raised px-3 text-ui text-ink sm:w-[160px]"
                                        />
                                        <input
                                            value={reason}
                                            onChange={(e) => { setReason(e.target.value); }}
                                            placeholder="Why, for the requester"
                                            aria-label="Refund reason"
                                            className="h-9 min-w-0 flex-1 rounded-sm border border-rule-strong bg-raised px-3 text-ui text-ink"
                                        />
                                        <Button
                                            variant="destructive"
                                            busy={busy === 'refund'}
                                            disabled={amount === '' || reason.trim() === ''}
                                            onClick={() => {
                                                setBusy('refund');
                                                router.post(`/console/support/${thread.reference}/refund`, { amount, reason }, {
                                                    preserveScroll: true,
                                                    onFinish: () => { setBusy(null); },
                                                });
                                            }}
                                        >
                                            Refund
                                        </Button>
                                    </div>
                                    {errors.amount !== undefined && <p role="alert" className="mt-2 text-ui font-semibold text-alert-ink">{errors.amount}</p>}
                                </div>
                            )}
                        </section>
                    )}
                </div>
            </div>
        </ConsoleShell>
    );
}
