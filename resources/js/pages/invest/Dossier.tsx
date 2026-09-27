import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { buttonClass } from '@/lib/button';
import { cx } from '@/lib/cx';
import { InvestorIcon, InvestorShell } from '@/components/InvestorShell';
import { ScoreRing, type OpportunityRow } from '@/components/InvestorWidgets';

interface Dossier extends Omit<OpportunityRow, 'grant' | 'seeking'> {
    cell: string | null;
    facts: {
        sector: string;
        operatingSince: string;
        staffOnSite: string;
        cac: { label: string; status: string };
        premises: string;
        lastVerified: string | null;
    };
    seeking: string;
    evidence: { kind: 'site' | 'owner' | 'registry' | 'enumeration'; title: string; detail: string; at: string }[];
    catchment: { highwayKm: number | null; sameTrade5km: number; verified15km: number };
    certificate: { orderId: number; reference: string; issuedOn: string } | null;
    documents: { id: number; title: string; description: string; open: boolean }[];
    grant: { status: string } | null;
    watching: boolean;
    interestedByUs: boolean;
    note: string | null;
}

type DossierProps = {
    dossier: Omit<Dossier, 'seeking'> & {
        seeking: {
            label: string;
            ticketSizeNaira: number | null;
            useOfFunds: string | null;
            summary: string | null;
            interested: number;
        };
    };
};

function longDate(iso: string | null): string {
    if (iso === null) {
        return 'Not yet';
    }

    return new Date(iso).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

const EVIDENCE_ICON: Record<Dossier['evidence'][number]['kind'], { path: string; tone: string }> = {
    site: {
        path: 'M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11zM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
        tone: 'bg-gold-soft text-gold',
    },
    owner: { path: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4.5 20.5c0-4 3.4-6.5 7.5-6.5s7.5 2.5 7.5 6.5', tone: 'bg-gold-soft text-gold' },
    registry: { path: 'M5 12.5l4.5 4.5L19 7.5', tone: 'bg-held-soft text-held' },
    enumeration: {
        path: 'M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11zM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
        tone: 'bg-gold-soft text-gold',
    },
};

/**
 * One business, for a verified investor.
 *
 * The mockup's dossier: the score and the facts behind it, what has been
 * established and when, where it is and what is around it, what it is
 * seeking, and the documents it has chosen to share. The position is an H3
 * cell, never a coordinate; the catchment figures were measured in the
 * database from the real position and arrive as rounded distances.
 */
export default function Dossier({ dossier }: DossierProps) {
    const [note, setNote] = useState(dossier.note ?? '');
    const [saved, setSaved] = useState<'idle' | 'saving' | 'saved'>('idle');
    const first = useRef(true);

    // Notes save themselves, a second after the last keystroke, because a
    // "Save" button under a notes box is the button nobody presses.
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }

        setSaved('saving');
        const timer = window.setTimeout(() => {
            router.post(
                `/invest/opportunities/${String(dossier.id)}/note`,
                { body: note },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => {
                        setSaved('saved');
                    },
                },
            );
        }, 900);

        return () => {
            window.clearTimeout(timer);
        };
    }, [note, dossier.id]);

    const post = (path: string, data: Record<string, string> = {}) => {
        router.post(`/invest/opportunities/${String(dossier.id)}/${path}`, data, { preserveScroll: true });
    };

    const room = dossier.grant?.status ?? null;
    const place = [dossier.activity, dossier.lga === null ? null : `${dossier.lga} LGA`, dossier.state]
        .filter(Boolean)
        .join(' · ');

    const facts: [string, string][] = [
        ['Sector', dossier.facts.sector],
        ['Operating since', dossier.facts.operatingSince],
        ['Staff on site', dossier.facts.staffOnSite],
        ['CAC registration', dossier.facts.cac.label],
        ['Premises', dossier.facts.premises],
        ['Last verified', longDate(dossier.facts.lastVerified)],
    ];

    return (
        <InvestorShell
            current="opportunities"
            crumbs={
                <>
                    <Link href="/invest/opportunities" className="hover:text-ink">
                        Opportunities
                    </Link>
                    {dossier.sector !== null && ` / ${dossier.sector}`}
                    {dossier.state !== null && ` / ${dossier.state}`}
                </>
            }
            title={
                <span className="flex flex-wrap items-center gap-3">
                    {dossier.name}
                    {dossier.verified && (
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-gold px-3 py-1 text-table font-bold tracking-normal text-on-accent">
                            <InvestorIcon size={15} path="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM8.5 12l2.5 2.5 4.5-5" />
                            GeoVerified
                        </span>
                    )}
                </span>
            }
            subtitle={
                <>
                    {place}
                    {dossier.cell !== null && (
                        <>
                            {' · '}
                            <span className="numeric-mono text-mono">cell {dossier.cell}</span>
                        </>
                    )}
                </>
            }
            actions={
                <>
                    <button
                        type="button"
                        onClick={() => {
                            post('watch');
                        }}
                        aria-pressed={dossier.watching}
                        className={cx(
                            buttonClass(dossier.watching ? 'soft' : 'secondary', 'field'),
                            'gap-2',
                        )}
                    >
                        <InvestorIcon size={18} path="M6.5 3.5h11v17l-5.5-4-5.5 4z" />
                        {dossier.watching ? 'On watchlist' : 'Watchlist'}
                    </button>
                    <Link
                        href={`/invest/opportunities/${String(dossier.id)}/commission`}
                        className={buttonClass('secondary', 'field')}
                    >
                        Commission re-verification
                    </Link>
                    {room === 'granted' ? (
                        <a href="#documents" className={cx(buttonClass('primary', 'field'), 'gap-2')}>
                            <InvestorIcon size={18} path="M5.5 10.5h13v10h-13zM8.5 10.5V7a3.5 3.5 0 0 1 7 0" />
                            Open data room
                        </a>
                    ) : (
                        <button
                            type="button"
                            disabled={room === 'requested'}
                            onClick={() => {
                                post('data-room');
                            }}
                            className={cx(buttonClass('primary', 'field'), 'gap-2')}
                        >
                            <InvestorIcon size={18} path="M5.5 10.5h13v10h-13zM8.5 10.5V7a3.5 3.5 0 0 1 7 0v3.5" />
                            {room === 'requested' ? 'Access requested' : 'Open data room'}
                        </button>
                    )}
                </>
            }
        >
            <Head title={dossier.name} />

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_400px] xl:items-start">
                <div className="flex flex-col gap-6">
                    <section className="flex flex-wrap items-center gap-8 rounded-card border border-rule bg-raised p-7 shadow-card">
                        <ScoreRing score={dossier.score} />
                        <dl className="grid flex-1 grid-cols-2 gap-x-8 gap-y-5 sm:grid-cols-3">
                            {facts.map(([term, value]) => (
                                <div key={term}>
                                    <dt className="text-label font-bold tracking-[0.05em] text-muted uppercase">
                                        {term}
                                    </dt>
                                    <dd className="mt-1 text-body font-bold text-ink">{value}</dd>
                                </div>
                            ))}
                        </dl>
                    </section>

                    <div className="grid gap-6 lg:grid-cols-2">
                        <section className="rounded-card border border-rule bg-raised p-6 shadow-card">
                            <h2 className="font-display text-display-s text-ink">Verification evidence</h2>
                            <ol className="relative mt-5 flex list-none flex-col gap-5">
                                <span aria-hidden="true" className="absolute top-4 bottom-4 left-[17px] w-px bg-rule-strong" />
                                {dossier.evidence.map((item) => {
                                    const icon = EVIDENCE_ICON[item.kind];

                                    return (
                                        <li key={`${item.kind}-${item.at}`} className="relative flex gap-3.5">
                                            <span
                                                className={cx(
                                                    'relative flex size-9 shrink-0 items-center justify-center rounded-sm',
                                                    icon.tone,
                                                )}
                                            >
                                                <InvestorIcon size={17} path={icon.path} />
                                            </span>
                                            <span className="flex flex-col">
                                                <span className="text-ui font-extrabold text-ink">{item.title}</span>
                                                <span className="text-table text-muted">
                                                    {longDate(item.at)} · {item.detail}
                                                </span>
                                            </span>
                                        </li>
                                    );
                                })}
                            </ol>
                            {dossier.certificate !== null && (
                                <a
                                    href={`/invest/opportunities/${String(dossier.id)}/certificates/${String(dossier.certificate.orderId)}.pdf`}
                                    className="mt-5 inline-flex text-ui font-extrabold text-gold hover:text-gold-dark"
                                >
                                    Download verification certificate (PDF)
                                </a>
                            )}
                        </section>

                        <section className="rounded-card border border-rule bg-raised p-6 shadow-card">
                            <div className="flex items-start justify-between gap-3">
                                <h2 className="font-display text-display-s text-ink">Location &amp; catchment</h2>
                                {dossier.cell !== null && (
                                    <span className="numeric-mono text-right text-[0.75rem] text-muted">
                                        res 7 ·<br />
                                        {dossier.cell.slice(0, 9)}
                                    </span>
                                )}
                            </div>
                            <Catchment />
                            <div className="mt-4 grid grid-cols-3 gap-2.5">
                                <Stat
                                    value={dossier.catchment.highwayKm === null ? '·' : `${String(dossier.catchment.highwayKm)} km`}
                                    label="to highway"
                                />
                                <Stat value={String(dossier.catchment.sameTrade5km)} label="same trade in 5 km" />
                                <Stat value={String(dossier.catchment.verified15km)} label="verified businesses in 15 km" />
                            </div>
                        </section>
                    </div>
                </div>

                <div className="flex flex-col gap-6">
                    <section className="rounded-card bg-ink p-7 text-inverse shadow-card">
                        <p className="text-label font-bold tracking-[0.05em] text-inverse/70 uppercase">Seeking</p>
                        <p className="mt-2 font-display text-display-m">{dossier.seeking.label}</p>
                        <dl className="mt-5 border-t border-inverse/15 pt-2">
                            {(
                                [
                                    ['Ticket size', dossier.seeking.ticketSizeNaira === null ? 'Not stated' : `₦${dossier.seeking.ticketSizeNaira.toLocaleString('en-NG')}`],
                                    ['Use of funds', dossier.seeking.useOfFunds ?? 'Not stated'],
                                    ['Interest expressed', `${String(dossier.seeking.interested)} ${dossier.seeking.interested === 1 ? 'investor' : 'investors'}`],
                                ] as const
                            ).map(([k, v]) => (
                                <div key={k} className="flex justify-between gap-4 py-2 text-ui">
                                    <dt className="text-inverse/75">{k}</dt>
                                    <dd className="text-right font-extrabold">{v}</dd>
                                </div>
                            ))}
                        </dl>
                        {dossier.seeking.summary !== null && (
                            <p className="mt-3 text-table text-inverse/75">{dossier.seeking.summary}</p>
                        )}
                        <button
                            type="button"
                            disabled={dossier.interestedByUs}
                            onClick={() => {
                                post('interest');
                            }}
                            className="mt-5 min-h-touch-lg w-full rounded-sm bg-logo text-ui font-extrabold text-ink hover:brightness-105 disabled:opacity-70"
                        >
                            {dossier.interestedByUs ? 'Interest expressed' : 'Express interest'}
                        </button>
                    </section>

                    <section id="documents" className="rounded-card border border-rule bg-raised p-6 shadow-card">
                        <h2 className="font-display text-display-s text-ink">Documents</h2>
                        <ul className="mt-3 flex list-none flex-col">
                            {dossier.certificate !== null && (
                                <DocRow
                                    title="Verification certificate"
                                    detail={`PDF · issued ${longDate(dossier.certificate.issuedOn)}`}
                                    href={`/invest/opportunities/${String(dossier.id)}/certificates/${String(dossier.certificate.orderId)}.pdf`}
                                />
                            )}
                            {dossier.documents.map((doc) => (
                                <DocRow
                                    key={doc.id}
                                    title={doc.title}
                                    detail={doc.description}
                                    href={doc.open ? `/invest/opportunities/${String(dossier.id)}/documents/${String(doc.id)}` : null}
                                />
                            ))}
                            {dossier.certificate === null && dossier.documents.length === 0 && (
                                <li className="py-3 text-ui text-muted">
                                    This business has not shared any documents yet.
                                </li>
                            )}
                        </ul>
                    </section>

                    <section className="rounded-card border border-rule bg-raised p-6 shadow-card">
                        <div className="flex items-baseline justify-between">
                            <h2 className="font-display text-display-s text-ink">Your notes</h2>
                            <span className="text-table text-faint" aria-live="polite">
                                {saved === 'saving' ? 'Saving' : saved === 'saved' ? 'Saved' : ''}
                            </span>
                        </div>
                        <label htmlFor="note" className="sr-only">
                            Your notes
                        </label>
                        <textarea
                            id="note"
                            value={note}
                            onChange={(e) => {
                                setNote(e.target.value);
                            }}
                            rows={4}
                            placeholder="Private to your team"
                            className="mt-3 w-full rounded-sm border border-rule-strong bg-raised p-3.5 text-ui text-ink placeholder:text-faint focus:border-gold"
                        />
                    </section>
                </div>
            </div>
        </InvestorShell>
    );
}

function Stat({ value, label }: { value: string; label: string }) {
    return (
        <div className="rounded-sm bg-surface px-3 py-3">
            <p className="text-body font-extrabold text-ink">{value}</p>
            <p className="text-[0.75rem] leading-snug text-muted">{label}</p>
        </div>
    );
}

function DocRow({ title, detail, href }: { title: string; detail: string; href: string | null }) {
    const locked = href === null;

    return (
        <li className="flex items-center gap-3.5 border-t border-rule py-3.5 first:border-t-0">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-sm bg-surface text-muted">
                <InvestorIcon
                    size={18}
                    path={
                        locked
                            ? 'M5.5 10.5h13v10h-13zM8.5 10.5V7a3.5 3.5 0 0 1 7 0v3.5'
                            : 'M6 3h8.5L19 7.5V21H6zM14 3v5h5'
                    }
                />
            </span>
            <span className="flex min-w-0 flex-1 flex-col">
                <span className="truncate text-ui font-extrabold text-ink">{title}</span>
                <span className="truncate text-table text-muted">{detail}</span>
            </span>
            {locked ? (
                <span className="text-table font-bold text-muted">Locked</span>
            ) : (
                <a href={href} className="text-table font-extrabold text-gold hover:text-gold-dark">
                    Download
                </a>
            )}
        </li>
    );
}

/**
 * The catchment diagram: the cell, and rings at five and fifteen kilometres.
 * A diagram of the distances below it, not a map, so it can never place the
 * business more precisely than the cell does.
 */
function Catchment() {
    return (
        <svg viewBox="0 0 290 280" className="mt-4 w-full rounded-sm bg-[#EEF1EC]" role="img" aria-label="The business's H3 cell with rings at 5 and 15 kilometres">
            <defs>
                <pattern id="hexes" width="26" height="45" patternUnits="userSpaceOnUse" patternTransform="scale(0.9)">
                    <path d="M13 0 26 7.5v15L13 30 0 22.5v-15zM13 30v15" fill="none" stroke="#D5DBD7" strokeWidth="1" />
                </pattern>
            </defs>
            <rect width="290" height="280" fill="url(#hexes)" />
            <path d="M0 190 290 150" stroke="#C9DDF5" strokeWidth="14" />
            <path d="M0 110 290 150" stroke="#FFFFFF" strokeWidth="10" />
            <circle cx="145" cy="140" r="114" fill="none" stroke="#2F5BEA" strokeOpacity="0.5" strokeDasharray="4 5" />
            <circle cx="145" cy="140" r="52" fill="#0E7C72" fillOpacity="0.08" stroke="#0E7C72" strokeWidth="1.5" />
            <path d="M145 118 164 129v22l-19 11-19-11v-22z" fill="#4DB8B0" stroke="#0E7C72" strokeWidth="2" />
            <circle cx="145" cy="140" r="7" fill="#FFFFFF" stroke="#0F1A17" strokeWidth="2.5" />
            <text x="199" y="128" fontSize="11" fontWeight="700" fill="#0A5E57">5 km</text>
            <text x="252" y="58" fontSize="11" fontWeight="700" fill="#2445B8">15 km</text>
        </svg>
    );
}
