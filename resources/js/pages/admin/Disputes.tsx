import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { ConsoleShell } from '@/components/ConsoleShell';
import { ItemsTable, MoneyCard, PurchasePill, Timeline, type PurchaseView } from '@/components/PurchaseParts';
import { stamp } from '@/lib/money';

/**
 * Buyer issues on product orders, oldest first. A ruling moves money one way
 * or the other and is final, so each asks for the reason in writing.
 */
export default function Disputes({ disputes }: { disputes: PurchaseView[] }) {
    return (
        <ConsoleShell current="disputes">
            <Head title="Disputes" />
            <div className="mx-auto max-w-[1100px] px-6 pb-20">
                <header className="mt-8 border-b border-rule pb-3">
                    <p className="text-label font-bold tracking-[0.05em] text-muted uppercase">Administration</p>
                    <h1 className="mt-1 font-display text-display-l text-ink">Disputes</h1>
                    <p className="mt-1 text-ui text-muted">Orders a buyer raised an issue on. The money is held until you rule.</p>
                </header>
                {disputes.length === 0 ? (
                    <p className="mt-8 text-ui text-muted">No open issues.</p>
                ) : (
                    <ul className="mt-6 flex list-none flex-col gap-8 p-0">
                        {disputes.map((order) => (
                            <li key={order.id}>
                                <Dispute order={order} />
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </ConsoleShell>
    );
}

function Dispute({ order }: { order: PurchaseView }) {
    const form = useForm({ for: 'buyer', note: '' });
    const disputedAt = order.timeline.find((s) => s.label === 'Buyer raised issue')?.at ?? null;

    const rule = (side: 'buyer' | 'merchant') => {
        form.transform((data) => ({ ...data, for: side }));
        form.post(`/admin/disputes/${String(order.id)}`, { preserveScroll: true });
    };

    return (
        <article className="rounded-card border border-rule bg-raised px-6 py-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="font-display text-display-s text-ink">
                    <span className="numeric-mono">{order.reference}</span> · {order.business.name}
                </h2>
                <PurchasePill order={order} size="sm" />
            </div>
            <p className="mt-1 text-table text-muted">
                Bought by {order.buyerName} · raised {stamp(disputedAt)}
            </p>
            <blockquote className="mt-4 rounded-sm bg-alert-soft px-4 py-3 text-ui text-ink">“{order.dispute}”</blockquote>
            <div className="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
                <ItemsTable order={order} />
                <div className="flex flex-col gap-5">
                    <MoneyCard order={order} side="buyer" />
                    <Timeline order={order} />
                </div>
            </div>
            <div className="mt-5 border-t border-rule pt-5">
                <label htmlFor={`note-${String(order.id)}`} className="text-label font-bold tracking-[0.05em] text-muted uppercase">
                    Your reasons, which both sides will ask for
                </label>
                <textarea
                    id={`note-${String(order.id)}`}
                    rows={3}
                    value={form.data.note}
                    onChange={(e) => { form.setData('note', e.target.value); }}
                    className="mt-2 w-full rounded-sm border border-rule-strong bg-raised p-3 text-ui text-ink focus:border-gold focus:outline-2 focus:outline-gold"
                />
                {form.errors.note !== undefined && <p className="mt-1 text-ui text-alert">{form.errors.note}</p>}
                <div className="mt-3 flex flex-wrap gap-3">
                    <Button variant="destructive" size="console" busy={form.processing} disabled={form.data.note.trim() === ''} onClick={() => { rule('buyer'); }}>
                        Refund the buyer
                    </Button>
                    <Button variant="secondary" size="console" busy={form.processing} disabled={form.data.note.trim() === ''} onClick={() => { rule('merchant'); }}>
                        Release to the merchant
                    </Button>
                </div>
            </div>
        </article>
    );
}
