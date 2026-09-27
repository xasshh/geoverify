import { Link } from '@inertiajs/react';
import { cx } from '@/lib/cx';
import { STATE_TILES } from '@/lib/stateTiles';

export interface OpportunityRow {
    id: number;
    enterpriseId: number;
    name: string;
    sector: string | null;
    sectorCode: string | null;
    activity: string | null;
    state: string | null;
    lga: string | null;
    score: number;
    since: number | null;
    seeking: string;
    seekingKey: string;
    status: { key: 'room' | 'reverifying' | 'verified' | 'listed'; label: string };
    grant: string | null;
    verified: boolean;
    lastVerified: string | null;
    publishedAt: string | null;
}

export interface StateCount {
    state: string;
    verified: number;
    open: number;
}



function normalise(name: string): string {
    const n = name.toLowerCase().replace(/\bstate\b/g, '').trim();

    return n === 'fct' || n === 'abuja' ? 'federal capital territory' : n;
}

/** Five steps from empty to the most verified state on screen. */
const SHADES = [
    'bg-sunken text-faint',
    'bg-[#D5EFEC] text-gold-dark',
    'bg-[#A9DDD7] text-gold-dark',
    'bg-[#5DBAB1] text-white',
    'bg-[#1E8C81] text-white',
    'bg-gold-dark text-white',
];

export function StateTileMap({
    counts,
    selected,
    onSelect,
    size = 52,
}: {
    counts: StateCount[];
    selected: string | null;
    onSelect: (state: string) => void;
    size?: number;
}) {
    const byName = new Map(counts.map((c) => [normalise(c.state), c]));
    const max = Math.max(1, ...counts.map((c) => c.verified));

    const shade = (verified: number) =>
        verified <= 0 ? SHADES[0] : SHADES[Math.min(5, 1 + Math.floor((verified / max) * 4.999))];

    const current = selected === null ? null : STATE_TILES.find((t) => t.name === selected) ?? null;
    const currentCount = current === null ? null : byName.get(normalise(current.name)) ?? null;
    const gap = Math.round(size * 0.11);

    return (
        <div className="flex flex-wrap items-end gap-6">
            <div
                role="group"
                aria-label="Verified businesses by state"
                className="relative shrink-0"
                style={{ width: 8 * size + 7 * gap, height: 7 * size + 6 * gap }}
            >
                {STATE_TILES.map((tile) => {
                    const count = byName.get(normalise(tile.name));
                    const verified = count?.verified ?? 0;
                    const active = selected === tile.name;

                    return (
                        <button
                            key={tile.code}
                            type="button"
                            onClick={() => {
                                onSelect(tile.name);
                            }}
                            aria-pressed={active}
                            title={`${tile.name}: ${String(verified)} verified`}
                            style={{
                                width: size,
                                height: size,
                                left: tile.col * (size + gap),
                                top: tile.row * (size + gap),
                            }}
                            className={cx(
                                'absolute flex items-center justify-center rounded-[10px] text-[0.8125rem] font-extrabold transition-transform hover:scale-105',
                                shade(verified),
                                active && 'ring-[3px] ring-ink',
                            )}
                        >
                            {tile.code}
                            <span className="sr-only">
                                {tile.name}, {verified} verified
                            </span>
                        </button>
                    );
                })}
            </div>

            <div className="flex min-w-[150px] flex-col gap-4">
                {current !== null && (
                    <div className="rounded-sm bg-surface px-4 py-3.5">
                        <p className="text-label font-bold tracking-[0.05em] text-muted uppercase">
                            Selected
                        </p>
                        <p className="mt-1 text-body font-extrabold text-ink">{current.name}</p>
                        <p className="mt-0.5 text-table text-muted">
                            {(currentCount?.verified ?? 0).toLocaleString('en-NG')} verified ·{' '}
                            {currentCount?.open ?? 0} open{' '}
                            {currentCount?.open === 1 ? 'opportunity' : 'opportunities'}
                        </p>
                    </div>
                )}
                <div>
                    <div className="flex gap-1">
                        {SHADES.slice(1).map((s) => (
                            <span key={s} className={cx('h-2.5 w-6 rounded-full', s)} />
                        ))}
                    </div>
                    <div className="mt-1.5 flex justify-between text-[0.75rem] text-faint">
                        <span>Fewer</span>
                        <span>More</span>
                    </div>
                </div>
            </div>
        </div>
    );
}

export function SectorBars({ sectors }: { sectors: { code: string; name: string; share: number }[] }) {
    if (sectors.length === 0) {
        return (
            <p className="mt-4 text-ui text-muted">
                No verified businesses carry a sector yet in this selection.
            </p>
        );
    }

    return (
        <ul className="mt-4 flex list-none flex-col gap-4">
            {sectors.map((sector) => (
                <li key={sector.code}>
                    <div className="flex items-baseline justify-between gap-3">
                        <span className="text-ui font-semibold text-ink">{sector.name}</span>
                        <span className="text-ui font-extrabold text-ink">{sector.share}%</span>
                    </div>
                    <div className="mt-1.5 h-2 rounded-full bg-sunken">
                        <div
                            className="h-2 rounded-full bg-gold"
                            style={{ width: `${String(Math.max(2, sector.share))}%` }}
                        />
                    </div>
                </li>
            ))}
        </ul>
    );
}

export function ScoreBar({ score }: { score: number }) {
    return (
        <span className="flex items-center gap-3">
            <span className="h-2 w-[70px] rounded-full bg-sunken" aria-hidden="true">
                <span
                    className="block h-2 rounded-full bg-gold"
                    style={{ width: `${String(score)}%` }}
                />
            </span>
            <span className="text-ui font-extrabold text-ink">{score}</span>
        </span>
    );
}

export function ScoreRing({ score, size = 132 }: { score: number; size?: number }) {
    const stroke = 12;
    const r = (size - stroke) / 2;
    const c = 2 * Math.PI * r;

    return (
        <div className="relative shrink-0" style={{ width: size, height: size }}>
            <svg width={size} height={size} aria-hidden="true" className="-rotate-90">
                <circle cx={size / 2} cy={size / 2} r={r} fill="none" strokeWidth={stroke} className="stroke-sunken" />
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={r}
                    fill="none"
                    strokeWidth={stroke}
                    strokeLinecap="round"
                    strokeDasharray={c}
                    strokeDashoffset={c * (1 - score / 100)}
                    className="stroke-gold"
                />
            </svg>
            <span className="absolute inset-0 flex flex-col items-center justify-center">
                <span className="text-[2.5rem] leading-none font-extrabold tracking-[-0.03em] text-ink">
                    {score}
                </span>
                <span className="mt-1 text-[0.6875rem] font-bold tracking-[0.05em] text-muted">
                    OF 100
                </span>
                <span className="sr-only">Verification score {score} of 100</span>
            </span>
        </div>
    );
}

export function OpportunityStatus({ status }: { status: OpportunityRow['status'] }) {
    const look = {
        room: 'bg-held-soft text-held-ink',
        verified: 'bg-gold-soft text-gold-dark',
        reverifying: 'bg-amber-soft text-amber-ink',
        listed: 'bg-graphite-soft text-muted',
    }[status.key];

    return (
        <span className={cx('inline-flex rounded-full px-3 py-1 text-table font-bold whitespace-nowrap', look)}>
            {status.label}
        </span>
    );
}

export function OpportunityTable({
    rows,
    empty,
}: {
    rows: OpportunityRow[];
    empty: string;
}) {
    if (rows.length === 0) {
        return (
            <p className="rounded-card border border-rule bg-raised px-6 py-10 text-center text-ui text-muted">
                {empty}
            </p>
        );
    }

    return (
        <div className="overflow-x-auto rounded-card border border-rule bg-raised">
            <table className="w-full min-w-[860px] border-collapse text-ui">
                <thead>
                    <tr className="border-b border-rule text-left text-table text-muted">
                        <th className="px-5 py-4 font-bold">Business</th>
                        <th className="px-5 py-4 font-bold">Sector</th>
                        <th className="px-5 py-4 font-bold">Location</th>
                        <th className="px-5 py-4 font-bold">Verification score</th>
                        <th className="px-5 py-4 font-bold">Since</th>
                        <th className="px-5 py-4 font-bold">Seeking</th>
                        <th className="px-5 py-4 font-bold">Status</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.id} className="border-b border-rule last:border-b-0 hover:bg-surface">
                            <td className="px-5 py-4">
                                <Link
                                    href={`/invest/opportunities/${String(row.id)}`}
                                    className="font-extrabold text-ink hover:text-gold"
                                >
                                    {row.name}
                                </Link>
                            </td>
                            <td className="px-5 py-4 text-muted">{row.sector ?? 'Not classified'}</td>
                            <td className="px-5 py-4 text-muted">
                                {[row.state === 'Federal Capital Territory' ? 'FCT' : row.state, row.lga]
                                    .filter(Boolean)
                                    .join(' · ') || 'Not resolved'}
                            </td>
                            <td className="px-5 py-4">
                                <ScoreBar score={row.score} />
                            </td>
                            <td className="px-5 py-4 text-muted">{row.since ?? '·'}</td>
                            <td className="px-5 py-4 text-muted">{row.seeking}</td>
                            <td className="px-5 py-4">
                                <OpportunityStatus status={row.status} />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** One of the three counts beside the action card, as the guide draws it. */
export function CountCard({
    value,
    title,
    body,
    href,
    link,
    tone,
}: {
    value: number;
    title: string;
    body: string;
    href: string;
    link: string;
    tone: 'teal' | 'blue' | 'amber';
}) {
    const disc = {
        teal: 'bg-gold-soft text-gold-dark',
        blue: 'bg-held-soft text-held',
        amber: 'bg-amber-soft text-amber',
    }[tone];

    return (
        <div className="flex flex-col rounded-card border border-rule bg-raised p-6 shadow-card">
            <span
                className={cx(
                    'flex size-[68px] items-center justify-center rounded-full text-[2.125rem] font-semibold',
                    disc,
                )}
            >
                {value}
            </span>
            <h3 className="mt-5 font-display text-display-s text-ink">{title}</h3>
            <p className="mt-1.5 text-ui text-muted">{body}</p>
            <Link
                href={href}
                className="mt-auto inline-flex items-center gap-1.5 pt-5 text-ui font-extrabold text-gold hover:text-gold-dark"
            >
                {link}
                <span aria-hidden="true">&rsaquo;</span>
            </Link>
        </div>
    );
}

export function FilterSelect({
    label,
    value,
    options,
    onChange,
    all,
}: {
    label: string;
    value: string | null;
    options: { value: string; label: string }[];
    onChange: (value: string | null) => void;
    all: string;
}) {
    return (
        <label className="flex items-center gap-3">
            <span className="text-ui font-semibold text-muted">{label}</span>
            <select
                value={value ?? ''}
                onChange={(e) => {
                    onChange(e.target.value === '' ? null : e.target.value);
                }}
                className="h-12 w-[210px] truncate rounded-sm border border-rule-strong bg-raised px-3.5 text-ui font-bold text-ink focus:border-gold"
            >
                <option value="">{all}</option>
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        </label>
    );
}
