import { useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { ConsoleShell } from '@/components/ConsoleShell';
import { StatusPill } from '@/components/StatusPill';

interface Correction {
    id: number;
    field: string;
    fieldLabel: string;
    currentValue: string | null;
    proposedValue: string | null;
    reason: string;
    proposedAt: string;
    hasEvidence: boolean;
    evidenceUrl: string | null;
    enterprise: {
        id: number;
        tradingName: string;
        ward: string | null;
        selfRegistered: boolean;
    };
    party: {
        code: string;
        name: string;
        acceptedBefore: number;
        rejectedBefore: number;
    };
}

function waitingDays(iso: string): number {
    return Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000);
}

/**
 * One correction, and the two facts it turns on.
 *
 * What the register says now and what the business says it should say, side by
 * side and the same size. Putting the proposed value in a form field would make
 * this a screen for editing the register, which it is not: it is a screen for
 * deciding whether to believe somebody.
 */
function Row({ correction }: { correction: Correction }) {
    const form = useForm({ decision: 'accepted', note: '' });
    const [deciding, setDeciding] = useState<'accepted' | 'rejected' | null>(null);
    const days = waitingDays(correction.proposedAt);

    const submit = (decision: 'accepted' | 'rejected') => {
        form.transform((data) => ({ ...data, decision }));
        form.post(`/console/corrections/${String(correction.id)}`, { preserveScroll: true });
    };

    return (
        <li className="rounded-sm border border-rule-strong p-4">
            <header className="flex flex-wrap items-baseline justify-between gap-3">
                <div>
                    <h2 className="font-display text-display-s text-ink">
                        {correction.enterprise.tradingName}
                    </h2>
                    <p className="numeric-mono text-label text-faint">
                        {correction.enterprise.ward ?? 'ward unresolved'} &middot;{' '}
                        {correction.party.name} ({correction.party.code})
                    </p>
                </div>
                <span className="numeric-mono text-label text-faint">
                    waiting {days} {days === 1 ? 'day' : 'days'}
                </span>
            </header>

            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                <div className="rounded-sm bg-raised px-3 py-2">
                    <p className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                        {correction.fieldLabel}, as recorded
                    </p>
                    <p className="text-ui text-ink">{correction.currentValue ?? 'nothing recorded'}</p>
                </div>
                <div className="rounded-sm border-l-2 border-gold bg-raised px-3 py-2">
                    <p className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                        What the business says
                    </p>
                    <p className="text-ui text-ink">{correction.proposedValue ?? 'nothing'}</p>
                </div>
            </div>

            <blockquote className="mt-3 border-l-2 border-rule-strong pl-3 text-ui text-muted">
                {correction.reason}
            </blockquote>

            <div className="mt-3 flex flex-wrap items-center gap-4">
                {correction.evidenceUrl !== null && (
                    <a
                        href={correction.evidenceUrl}
                        target="_blank"
                        rel="noreferrer"
                        className="text-ui text-gold underline underline-offset-2"
                    >
                        Open the document they attached
                    </a>
                )}

                {/* A first correction and a fifth are not the same request, and
                    a supervisor should not have to go looking to find that out. */}
                <span className="text-label text-faint">
                    This party: {correction.party.acceptedBefore} accepted,{' '}
                    {correction.party.rejectedBefore} refused
                </span>

                {correction.enterprise.selfRegistered && (
                    <StatusPill tone="held" label="Self registered" size="sm" />
                )}
            </div>

            {deciding === null ? (
                <div className="mt-4 flex flex-wrap gap-3">
                    <Button
                        onClick={() => {
                            setDeciding('accepted');
                        }}
                    >
                        Accept
                    </Button>
                    <Button
                        variant="secondary"
                        onClick={() => {
                            setDeciding('rejected');
                        }}
                    >
                        Do not accept
                    </Button>
                </div>
            ) : (
                <div className="mt-4 flex flex-col gap-2 rounded-sm border border-rule-strong bg-raised p-3">
                    <label
                        htmlFor={`note-${String(correction.id)}`}
                        className="text-label font-semibold tracking-[0.12em] text-muted uppercase"
                    >
                        {deciding === 'accepted' ? 'Why you accepted it' : 'Why you did not'}
                    </label>
                    <p className="text-label text-faint">
                        The business is shown this. A refusal with no reason is somebody told no by
                        a system.
                    </p>
                    <textarea
                        id={`note-${String(correction.id)}`}
                        rows={2}
                        value={form.data.note}
                        onChange={(event) => {
                            form.setData('note', event.target.value);
                        }}
                        className="w-full rounded-sm border border-rule-strong bg-surface px-3 py-2 text-ui text-ink"
                    />
                    {Object.values(form.errors).map((error) => (
                        <p key={error} className="text-label text-alert">
                            {error}
                        </p>
                    ))}
                    <div className="flex gap-3">
                        <Button
                            onClick={() => {
                                submit(deciding);
                            }}
                            busy={form.processing}
                        >
                            Confirm
                        </Button>
                        <button
                            type="button"
                            onClick={() => {
                                setDeciding(null);
                            }}
                            className="text-ui text-muted underline underline-offset-2"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            )}
        </li>
    );
}

/**
 * Corrections a supervisor has to rule on.
 *
 * Oldest first, which is the opposite of the observation queue. A capture is
 * triaged by how suspicious it looks, because the risk is something false
 * entering the register. A correction is somebody at the counter having told us
 * we have their own name wrong, and the cost of that is measured in how long
 * they have been standing there.
 */
export default function Corrections({ corrections }: { corrections: Correction[] }) {
    const flash = usePage().props.flash.status;

    return (
        <ConsoleShell current="corrections">
            <Head title="Corrections" />

            <div className="mx-auto max-w-[1000px] px-6 pb-20">
                <header className="mt-8 flex flex-wrap items-baseline justify-between gap-4 border-b-[1.5px] border-ink pb-3">
                    <div>
                        <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
                            Supervision
                        </p>
                        <h1 className="font-display text-display-m text-ink">Corrections</h1>
                    </div>
                    <p className="numeric-mono text-mono text-muted">
                        {corrections.length} waiting
                    </p>
                </header>

                {flash !== null && (
                    <p className="mt-4 border-l-2 border-green bg-raised px-4 py-2.5 text-ui text-ink">
                        {flash}
                    </p>
                )}

                <p className="mt-6 max-w-[70ch] text-ui text-muted">
                    A business saying the register has something wrong about it. Accepting does not
                    edit what the officer recorded: it appends the business's account beside it and
                    moves the listing forward. What was observed in the field stays exactly as it
                    was observed.
                </p>

                {corrections.length === 0 ? (
                    <p className="mt-8 border-l-2 border-rule-strong bg-raised px-4 py-3 text-ui text-muted">
                        Nothing is waiting. Corrections arrive here when a business that manages a
                        listing tells us something on it is wrong.
                    </p>
                ) : (
                    <ul className="mt-8 flex flex-col gap-4">
                        {corrections.map((correction) => (
                            <Row key={correction.id} correction={correction} />
                        ))}
                    </ul>
                )}
            </div>
        </ConsoleShell>
    );
}
