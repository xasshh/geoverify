import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/Button';
import { ConsoleShell } from '@/components/ConsoleShell';
import { cx } from '@/lib/cx';

interface Row {
    id: number;
    business: string;
    rating: number;
    body: string | null;
    status: 'published' | 'hidden';
    openReports: number;
    reasons: string | null;
    note: string | null;
    on: string;
}

function Item({ review }: { review: Row }) {
    const [note, setNote] = useState('');

    return (
        <li className={cx('rounded-card border bg-raised px-5 py-4', review.openReports > 0 ? 'border-alert/40' : 'border-rule')}>
            <p className="flex flex-wrap items-center justify-between gap-2 text-ui">
                <span className="font-bold text-ink">
                    {review.business} · {'★'.repeat(review.rating)}
                </span>
                <span className={cx('rounded-full px-2.5 py-0.5 text-table font-bold', review.status === 'hidden' ? 'bg-sunken text-muted' : 'bg-green-soft text-green')}>{review.status}</span>
            </p>
            {review.body !== null && <p className="mt-2 text-ui text-ink">{review.body}</p>}
            {review.openReports > 0 && (
                <p className="mt-2 max-w-none rounded-sm bg-alert-soft px-3 py-2 text-table text-alert-ink">
                    {review.openReports} {review.openReports === 1 ? 'report' : 'reports'}: {review.reasons}
                </p>
            )}
            {review.note !== null && <p className="mt-2 text-table text-muted">Last decision: {review.note}</p>}
            <div className="mt-3 flex flex-wrap gap-2">
                <input
                    value={note}
                    onChange={(e) => {
                        setNote(e.target.value);
                    }}
                    placeholder="Why"
                    aria-label={`Reason for review ${String(review.id)}`}
                    className="h-9 min-w-0 flex-1 rounded-sm border border-rule-strong bg-raised px-3 text-ui text-ink"
                />
                <Button
                    variant={review.status === 'hidden' ? 'secondary' : 'destructive'}
                    size="console"
                    disabled={note.trim() === ''}
                    onClick={() => {
                        router.post(`/admin/reviews/${String(review.id)}`, { hide: review.status !== 'hidden', note }, { preserveScroll: true });
                    }}
                >
                    {review.status === 'hidden' ? 'Restore' : 'Hide'}
                </Button>
            </div>
        </li>
    );
}

/** Reviews buyers wrote, reported ones first. Hidden or restored, never deleted. */
export default function Reviews({ reviews }: { reviews: Row[] }) {
    const flash = usePage().props.flash.status;

    return (
        <ConsoleShell current="reviews">
            <Head title="Reviews" />
            <div className="mx-auto max-w-[1000px] px-6 pb-20">
                <header className="mt-8 border-b border-rule pb-3">
                    <p className="text-label font-bold tracking-[0.05em] text-muted uppercase">Administration</p>
                    <h1 className="mt-1 font-display text-display-l text-ink">Reviews</h1>
                </header>
                {flash !== null && <p className="mt-5 max-w-none rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green">{flash}</p>}
                {reviews.length === 0 ? (
                    <p className="mt-6 text-ui text-muted">No reviews yet.</p>
                ) : (
                    <ul className="mt-6 flex list-none flex-col gap-3 p-0">
                        {reviews.map((r) => (
                            <Item key={r.id} review={r} />
                        ))}
                    </ul>
                )}
            </div>
        </ConsoleShell>
    );
}
