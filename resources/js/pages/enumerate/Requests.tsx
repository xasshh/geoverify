import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { EnumerateShell } from '@/components/EnumerateShell';
import { RequestTable } from '@/components/EnumerateParts';
import { cx } from '@/lib/cx';
import type { EnumerateFrame, RequestRow } from '@/lib/enumerate';

type Filter = 'all' | 'active' | 'done';

/** My verifications: everything this account has paid for, newest first. */
export default function Requests({ frame, requests }: { frame: EnumerateFrame; requests: RequestRow[] }) {
    const [filter, setFilter] = useState<Filter>('all');
    const done = (r: RequestRow) => r.status === 'passed' || r.status === 'failed' || r.status === 'completed';
    const shown = requests.filter((r) => (filter === 'all' ? true : filter === 'done' ? done(r) : !done(r)));

    const count: Record<Filter, number> = {
        all: requests.length,
        active: requests.filter((r) => !done(r)).length,
        done: requests.filter(done).length,
    };

    return (
        <EnumerateShell
            current="requests"
            frame={frame}
            title="My verifications"
            actions={
                <Link href="/enumerate/verify" className="flex h-11 items-center rounded-sm bg-gold px-4 text-ui font-extrabold text-on-accent hover:bg-gold-dark">
                    + New verification
                </Link>
            }
        >
            <Head title="My verifications" />

            <section className="overflow-hidden rounded-card border border-rule bg-raised">
                <div className="flex flex-wrap gap-2 px-6 pt-5 pb-4">
                    {(
                        [
                            ['all', 'All'],
                            ['active', 'In progress'],
                            ['done', 'Completed'],
                        ] as const
                    ).map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            aria-pressed={filter === key}
                            onClick={() => { setFilter(key); }}
                            className={cx(
                                'min-h-[38px] rounded-full border px-4 text-table font-bold',
                                filter === key ? 'border-ink bg-ink text-inverse' : 'border-rule-strong bg-raised text-ink hover:bg-sunken',
                            )}
                        >
                            {label} · {count[key]}
                        </button>
                    ))}
                </div>
                <RequestTable rows={shown} empty={filter === 'all' ? 'You have not requested a verification yet.' : 'Nothing here.'} />
            </section>
        </EnumerateShell>
    );
}
