import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { Button } from '@/components/Button';
import { ConsoleShell } from '@/components/ConsoleShell';
import { cx } from '@/lib/cx';

type Status = 'draft' | 'submitted' | 'changes_requested' | 'approved' | 'rejected';

interface Row {
    id: number;
    reference: string;
    name: string | null;
    phone: string | null;
    submittedAt: string | null;
    languages: string[];
}

interface Selected {
    id: number;
    reference: string;
    status: Status;
    submittedAt: string | null;
    decidedAt: string | null;
    decisionNote: string | null;
    staffRef: string | null;
    email: string | null;
    about: Record<string, string | number | string[] | null>;
    places: { residence: string; origin: string };
    background: {
        qualification: string | null;
        experience: string[];
        experience_years: number | null;
        guarantor: Record<string, string> | null;
        next_of_kin: Record<string, string> | null;
        ninLast4: string | null;
        bvnLast4: string | null;
    };
    device: Record<string, string | number | boolean | null> | null;
    consents: Record<string, string>;
    signedName: string | null;
    files: { id: number; kind: string; label: string | null; mime: string; sizeBytes: number; uploadedAt: string }[];
    missing: Record<string, string[]>;
}

interface Props {
    status: Status;
    counts: Partial<Record<Status, number>>;
    rows: Row[];
    selected: Selected | null;
    choices?: { qualifications: Record<string, string>; experience: Record<string, string>; fileKinds: Record<string, string>; consents: Record<string, string> };
}

const TABS: { key: Status; label: string }[] = [
    { key: 'submitted', label: 'Waiting' },
    { key: 'changes_requested', label: 'Sent back' },
    { key: 'approved', label: 'Approved' },
    { key: 'rejected', label: 'Turned down' },
    { key: 'draft', label: 'Not sent yet' },
];

const MODES = ['buildings', 'points', 'lines', 'areas'] as const;

function when(iso: string | null): string {
    return iso === null ? 'not yet' : new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

/**
 * Applications to become a field agent: a queue, and one application opened.
 *
 * Approving makes the officer through the same action as the People page, so
 * the first password appears once in the flash, exactly as it does there.
 */
export default function AgentApplications({ status, counts, rows, selected, choices }: Props) {
    const page = usePage();
    const errors = page.props.errors as Record<string, string | undefined>;

    return (
        <ConsoleShell current="agentApplications">
            <Head title="Agent applications" />
            <div className="mx-auto max-w-[1320px] px-6 pb-20">
                <header className="mt-8 border-b border-rule pb-3">
                    <h1 className="font-display text-display-l text-ink">Agent applications</h1>
                    <p className="mt-1 text-ui text-muted">People asking to join the field team. Approving one creates their officer account.</p>
                </header>

                {page.props.flash.status !== null && <p className="mt-5 rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green">{page.props.flash.status}</p>}
                {errors.decision !== undefined && <p className="mt-5 rounded-sm bg-alert-soft px-4 py-3 text-ui font-semibold text-alert-ink">{errors.decision}</p>}

                <nav className="mt-5 flex flex-wrap gap-2" aria-label="Application status">
                    {TABS.map((t) => (
                        <Link
                            key={t.key}
                            href={`/admin/agent-applications?status=${t.key}`}
                            className={cx('rounded-full border px-3.5 py-1.5 text-ui font-bold', selected === null && status === t.key ? 'border-gold bg-gold-soft text-gold-dark' : 'border-rule-strong text-ink')}
                        >
                            {t.label} <span className="text-muted">{counts[t.key] ?? 0}</span>
                        </Link>
                    ))}
                </nav>

                {selected === null ? (
                    rows.length === 0 ? (
                        <p className="mt-5 rounded-card border border-rule bg-raised px-6 py-8 text-center text-ui text-muted">None here.</p>
                    ) : (
                        <ul className="mt-5 overflow-hidden rounded-card border border-rule bg-raised">
                            {rows.map((r) => (
                                <li key={r.id} className="border-b border-rule last:border-b-0">
                                    <Link href={`/admin/agent-applications/${String(r.id)}`} className="flex flex-wrap items-center gap-4 px-5 py-4 hover:bg-sunken/60">
                                        <span className="min-w-0 flex-1">
                                            <span className="block text-body font-bold text-ink">{r.name}</span>
                                            <span className="block text-table text-muted">
                                                {[r.reference, r.phone, r.languages.join(', ')].filter((x) => x !== null && x !== '').join(' · ')}
                                            </span>
                                        </span>
                                        <span className="text-table text-muted">Sent {when(r.submittedAt)}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )
                ) : (
                    <Detail a={selected} choices={choices} />
                )}
            </div>
        </ConsoleShell>
    );
}

function Block({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-card border border-rule bg-raised px-5 py-4">
            <h2 className="text-body font-extrabold text-ink">{title}</h2>
            <dl className="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-[180px_1fr]">{children}</dl>
        </section>
    );
}

function Item({ k, v }: { k: string; v: ReactNode }) {
    return (
        <>
            <dt className="text-table text-muted">{k}</dt>
            <dd className="text-ui text-ink">{v === null || v === undefined || v === '' ? <span className="text-faint">not given</span> : v}</dd>
        </>
    );
}

function Detail({ a, choices }: { a: Selected; choices: Props['choices'] }) {
    const [note, setNote] = useState('');
    const [role, setRole] = useState('officer');
    const [modes, setModes] = useState<string[]>(['buildings', 'points']);
    const [score, setScore] = useState('');
    const decide = (decision: 'approve' | 'send_back' | 'reject') => {
        router.post(`/admin/agent-applications/${String(a.id)}/decide`, {
            decision,
            note: note === '' ? null : note,
            role,
            capture_modes: modes,
            assessment_score: score === '' ? null : Number(score),
        });
    };
    const ab = a.about;
    const bg = a.background;
    const d = a.device ?? {};

    return (
        <div className="mt-5 grid gap-4 xl:grid-cols-[1fr_360px]">
            <div className="flex flex-col gap-4">
                <p className="text-table text-muted">
                    <Link href={`/admin/agent-applications?status=${a.status}`} className="font-bold text-gold-dark">← Back to the list</Link> · {a.reference} · sent {when(a.submittedAt)}
                </p>
                <Block title="About them">
                    <Item k="Name" v={[ab.first_name, ab.middle_name, ab.surname].filter(Boolean).join(' ')} />
                    <Item k="Date of birth" v={ab.date_of_birth} />
                    <Item k="Sex" v={ab.sex} />
                    <Item k="Email" v={a.email} />
                    <Item k="Phone" v={ab.phone} />
                    <Item k="WhatsApp" v={ab.whatsapp} />
                    <Item k="Address" v={ab.residential_address} />
                    <Item k="Lives in" v={a.places.residence} />
                    <Item k="From" v={a.places.origin} />
                    <Item k="Languages" v={Array.isArray(ab.languages) ? ab.languages.join(', ') : null} />
                </Block>
                <Block title="Background">
                    <Item k="NIN" v={bg.ninLast4 === null ? null : `ends ${bg.ninLast4} (declared; check it against the ID)`} />
                    <Item k="BVN" v={bg.bvnLast4 === null ? null : `ends ${bg.bvnLast4}`} />
                    <Item k="Qualification" v={bg.qualification === null ? null : choices?.qualifications[bg.qualification] ?? bg.qualification} />
                    <Item k="Experience" v={[bg.experience.map((e) => choices?.experience[e] ?? e).join(', '), bg.experience_years === null ? '' : `${String(bg.experience_years)} years`].filter(Boolean).join(' · ')} />
                    <Item k="Guarantor" v={bg.guarantor === null ? null : `${bg.guarantor.name ?? ''}, ${bg.guarantor.occupation ?? ''} · ${bg.guarantor.phone ?? ''} · ${bg.guarantor.address ?? ''}`} />
                    <Item k="Next of kin" v={bg.next_of_kin === null ? null : `${bg.next_of_kin.name ?? ''} (${bg.next_of_kin.relationship ?? ''}) · ${bg.next_of_kin.phone ?? ''}`} />
                </Block>
                <Block title="Phone">
                    <Item k="Whose phone" v={d.ownership === 'company' ? 'Company issued' : d.ownership === 'personal' ? 'Their own' : null} />
                    <Item k="Power bank" v={d.power_bank === true ? 'Yes' : d.power_bank === false ? 'No' : null} />
                    <Item k="Best GPS accuracy" v={typeof d.gps_best_accuracy_m === 'number' ? `${String(d.gps_best_accuracy_m)} m from ${String(d.gps_samples ?? '?')} readings` : null} />
                    <Item k="Browser space" v={typeof d.storage_free_mb === 'number' ? `${String(d.storage_free_mb)} MB` : null} />
                    <Item k="Phone" v={typeof d.user_agent === 'string' ? <span className="font-mono text-[0.75rem]">{d.user_agent}</span> : null} />
                </Block>
                <Block title="Agreements">
                    {Object.entries(choices?.consents ?? {}).map(([k, text]) => (
                        <Item key={k} k={text.split(' ').slice(0, 4).join(' ') + '…'} v={a.consents[k] === undefined ? null : `Agreed ${when(a.consents[k])}`} />
                    ))}
                    <Item k="Signed as" v={a.signedName} />
                </Block>
                <section className="rounded-card border border-rule bg-raised px-5 py-4">
                    <h2 className="text-body font-extrabold text-ink">Documents</h2>
                    <p className="text-table text-muted">Opening one is recorded in the audit log.</p>
                    <ul className="mt-3 flex flex-col gap-2">
                        {a.files.map((f) => (
                            <li key={f.id} className="flex items-center justify-between gap-3 rounded-sm border border-rule px-3 py-2">
                                <span className="text-ui text-ink">
                                    {choices?.fileKinds[f.kind] ?? f.kind}
                                    {f.label !== null && <span className="text-muted"> ({f.label})</span>}
                                </span>
                                <a href={`/admin/agent-applications/${String(a.id)}/files/${String(f.id)}`} target="_blank" rel="noreferrer" className="text-ui font-bold text-gold-dark">
                                    Open
                                </a>
                            </li>
                        ))}
                        {a.files.length === 0 && <li className="text-ui text-muted">None uploaded.</li>}
                    </ul>
                </section>
            </div>

            <aside className="h-fit rounded-card border border-rule bg-raised px-5 py-5 xl:sticky xl:top-6">
                {a.status === 'submitted' ? (
                    <>
                        <h2 className="text-body font-extrabold text-ink">Decide</h2>
                        <label className="mt-3 block text-table text-muted">
                            Becomes
                            <select value={role} onChange={(e) => { setRole(e.target.value); }} className="mt-1 h-10 w-full rounded-sm border border-rule-strong bg-raised px-2 text-ui">
                                <option value="officer">Field officer</option>
                                <option value="desk_digitiser">Desk digitiser</option>
                            </select>
                        </label>
                        <fieldset className="mt-3">
                            <legend className="text-table text-muted">Certified to capture</legend>
                            <div className="mt-1 flex flex-wrap gap-2">
                                {MODES.map((m) => {
                                    const on = modes.includes(m);
                                    return (
                                        <button key={m} type="button" aria-pressed={on} onClick={() => { setModes(on ? modes.filter((x) => x !== m) : [...modes, m]); }} className={cx('rounded-full border px-3 py-1 text-table font-bold', on ? 'border-gold bg-gold-soft text-gold-dark' : 'border-rule-strong')}>
                                            {m}
                                        </button>
                                    );
                                })}
                            </div>
                        </fieldset>
                        <label className="mt-3 block text-table text-muted">
                            Assessment score (if training is done)
                            <input type="number" min={0} max={100} value={score} onChange={(e) => { setScore(e.target.value); }} className="mt-1 h-10 w-full rounded-sm border border-rule-strong bg-raised px-2 text-ui" />
                        </label>
                        <label className="mt-3 block text-table text-muted">
                            Note (the applicant reads it if you send it back or turn it down)
                            <textarea value={note} onChange={(e) => { setNote(e.target.value); }} rows={3} className="mt-1 w-full rounded-sm border border-rule-strong bg-raised px-2 py-1.5 text-ui" />
                        </label>
                        <div className="mt-4 flex flex-col gap-2">
                            <Button variant="primary" onClick={() => { decide('approve'); }}>
                                Approve and create officer
                            </Button>
                            <Button disabled={note.trim().length < 10} onClick={() => { decide('send_back'); }}>
                                Send back for changes
                            </Button>
                            <Button variant="destructive" disabled={note.trim().length < 10} onClick={() => { decide('reject'); }}>
                                Turn down
                            </Button>
                        </div>
                    </>
                ) : (
                    <>
                        <h2 className="text-body font-extrabold text-ink">Status: {a.status.replace('_', ' ')}</h2>
                        {a.staffRef !== null && <p className="mt-1 text-ui text-ink">Officer {a.staffRef}</p>}
                        {a.decisionNote !== null && <p className="mt-1 text-ui text-muted">{a.decisionNote}</p>}
                        {a.status === 'draft' && Object.keys(a.missing).length > 0 && (
                            <p className="mt-2 text-table text-muted">Still missing: {Object.values(a.missing).flat().join(', ')}</p>
                        )}
                    </>
                )}
            </aside>
        </div>
    );
}
