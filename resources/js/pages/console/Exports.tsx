import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { AppBar } from '@/components/AppBar';
import { consoleLinks } from '@/lib/consoleNav';
import { cx } from '@/lib/cx';

interface Cell {
    id: number;
    h3: string;
    captured: number;
    accepted: number;
}

interface Taken {
    id: number;
    takenAt: string;
    by: string | null;
    format: string | null;
    rows: number | null;
    bytes: number | null;
    sha256: string | null;
    h3: string | null;
    acceptedOnly: boolean | null;
}

interface ExportsProps {
    areas: Array<{ id: number; name: string }>;
    area: { id: number; name: string; client: string | null; contractRef: string | null } | null;
    counts: {
        accepted: number;
        captured: number;
        acceptedEnterprises: number;
        enterprises: number;
    } | null;
    cells: Cell[];
    recent: Taken[];
    packAvailable: boolean;
}

function bytes(value: number | null): string {
    if (value === null) {
        return '';
    }

    return value < 1024
        ? `${String(value)} B`
        : value < 1024 * 1024
          ? `${(value / 1024).toFixed(1)} KB`
          : `${(value / (1024 * 1024)).toFixed(1)} MB`;
}

function Stat({ label, value, note }: { label: string; value: string; note?: string }) {
    return (
        <div className="flex flex-col gap-0.5 border-l-2 border-rule-strong pl-3">
            <span className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                {label}
            </span>
            <span className="numeric-mono text-display-s text-ink">{value}</span>
            {note !== undefined && <span className="text-label text-faint">{note}</span>}
        </div>
    );
}

/**
 * Evidence output.
 *
 * Two decisions are made before anything downloads, and both are on the page
 * rather than in a menu: how much ground the file covers, and whether it holds
 * only what a supervisor accepted. A client paying for a register is buying the
 * accepted records, so that is the default, and taking everything is a
 * deliberate act rather than the path of least resistance.
 *
 * What has already left is listed underneath with the hash of each file, because
 * a supervisor about to send a register should be able to see that somebody sent
 * one an hour ago without going looking for the log.
 */
export default function Exports({
    areas,
    area,
    counts,
    cells,
    recent,
    packAvailable,
}: ExportsProps) {
    const [cellId, setCellId] = useState<number | null>(null);
    const [includeAll, setIncludeAll] = useState(false);

    const chosen = cells.find((cell) => cell.id === cellId) ?? null;

    const query = (): string => {
        const parts = [`area=${String(area?.id ?? 0)}`];

        if (cellId !== null) {
            parts.push(`cell=${String(cellId)}`);
        }

        if (includeAll) {
            parts.push('all=1');
        }

        return parts.join('&');
    };

    const records = counts === null ? 0 : includeAll ? counts.captured : counts.accepted;
    const businesses =
        counts === null ? 0 : includeAll ? counts.enterprises : counts.acceptedEnterprises;

    return (
        <div data-mode="daylight" className="min-h-dvh bg-surface text-ink">
            <Head title="Exports" />
            <AppBar
                variant="console"
                links={consoleLinks('exports')}
            />

            <div className="mx-auto max-w-5xl px-6 pb-20">
                <header className="mt-8 flex flex-wrap items-baseline justify-between gap-4 border-b-[1.5px] border-ink pb-3">
                    <div>
                        <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
                            Evidence output
                        </p>
                        <h1 className="font-display text-display-m text-ink">Exports</h1>
                        {area !== null && area.client !== null && (
                            <p className="mt-1 numeric-mono text-mono text-muted">
                                {area.client}
                                {area.contractRef !== null && ` / ${area.contractRef}`}
                            </p>
                        )}
                    </div>

                    <select
                        value={area?.id ?? ''}
                        onChange={(event) => {
                            router.get(
                                '/console/exports',
                                { area: event.target.value },
                                { preserveState: false, replace: true },
                            );
                        }}
                        className="rounded-sm border border-rule-strong bg-surface px-3 py-1.5 text-ui text-ink"
                    >
                        {areas.map((option) => (
                            <option key={option.id} value={option.id}>
                                {option.name}
                            </option>
                        ))}
                    </select>
                </header>

                {area === null || counts === null ? (
                    <p className="mt-8 text-ui text-muted">
                        No mandate is loaded, so there is nothing to export.
                    </p>
                ) : (
                    <>
                        <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Stat label="Accepted" value={counts.accepted.toLocaleString()} note="structures" />
                            <Stat label="Captured" value={counts.captured.toLocaleString()} note="structures" />
                            <Stat
                                label="Accepted"
                                value={counts.acceptedEnterprises.toLocaleString()}
                                note="businesses"
                            />
                            <Stat
                                label="Captured"
                                value={counts.enterprises.toLocaleString()}
                                note="businesses"
                            />
                        </div>

                        <section className="mt-8 rounded-sm border border-rule-strong p-4">
                            <h2 className="font-display text-display-s text-ink">What the file covers</h2>

                            <div className="mt-3 flex flex-col gap-3">
                                <label className="flex flex-col gap-1">
                                    <span className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                                        Ground
                                    </span>
                                    <select
                                        value={cellId ?? ''}
                                        onChange={(event) => {
                                            setCellId(
                                                event.target.value === ''
                                                    ? null
                                                    : Number(event.target.value),
                                            );
                                        }}
                                        className="max-w-md rounded-sm border border-rule-strong bg-surface px-3 py-1.5 text-ui text-ink"
                                    >
                                        <option value="">The whole mandate</option>
                                        {cells.map((cell) => (
                                            <option key={cell.id} value={cell.id}>
                                                {cell.h3} ({cell.accepted} accepted of {cell.captured})
                                            </option>
                                        ))}
                                    </select>
                                </label>

                                <label className="flex items-start gap-2.5">
                                    <input
                                        type="checkbox"
                                        checked={includeAll}
                                        onChange={(event) => {
                                            setIncludeAll(event.target.checked);
                                        }}
                                        className="mt-0.5"
                                    />
                                    <span className="flex flex-col gap-0.5">
                                        <span className="text-ui text-ink">
                                            Include records that have not been accepted
                                        </span>
                                        <span className="max-w-[60ch] text-label text-faint">
                                            A client is buying the register a supervisor accepted.
                                            Everything captured includes work that is still in review
                                            or was sent back, so this is for an internal audit rather
                                            than a delivery.
                                        </span>
                                    </span>
                                </label>
                            </div>

                            <p
                                className={cx(
                                    'mt-4 border-l-2 pl-3 text-ui',
                                    includeAll ? 'border-amber text-amber' : 'border-green text-muted',
                                )}
                            >
                                {records.toLocaleString()} structures and {businesses.toLocaleString()}{' '}
                                businesses
                                {chosen === null ? ' across the whole mandate' : ` in ${chosen.h3}`}
                                {includeAll ? ', including unaccepted work.' : ', accepted only.'}
                            </p>

                            <div className="mt-4 flex flex-wrap gap-3">
                                <a
                                    href={`/console/exports/structures.geojson?${query()}`}
                                    className="rounded-sm border border-rule-strong bg-raised px-4 py-2 text-ui text-ink hover:border-gold"
                                >
                                    Structures (GeoJSON)
                                </a>
                                <a
                                    href={`/console/exports/enterprises.csv?${query()}`}
                                    className="rounded-sm border border-rule-strong bg-raised px-4 py-2 text-ui text-ink hover:border-gold"
                                >
                                    Business register (CSV)
                                </a>

                                {/* The pack is per cell. A mandate sized one
                                    would be thousands of pages and nobody has
                                    ever wanted that. */}
                                {chosen !== null && packAvailable && (
                                    <a
                                        href={`/console/exports/cells/${String(chosen.id)}/pack.pdf${
                                            includeAll ? '?all=1' : ''
                                        }`}
                                        className="rounded-sm border border-gold bg-gold px-4 py-2 text-ui font-semibold text-on-accent"
                                    >
                                        Evidence pack (PDF)
                                    </a>
                                )}
                            </div>

                            {chosen === null && (
                                <p className="mt-3 text-label text-faint">
                                    Choose a cell above to print its evidence pack: the records, the
                                    walk, the photographs and the full audit log as one document.
                                </p>
                            )}

                            {chosen !== null && ! packAvailable && (
                                <p className="mt-3 border-l-2 border-amber pl-3 text-label text-amber">
                                    No headless browser is installed on this server, so the evidence
                                    pack cannot be printed here. Install Chromium, or set
                                    CHROMIUM_BINARY.
                                </p>
                            )}

                            <p className="mt-3 text-label text-faint">
                                Every download is written to the audit log with the SHA-256 of the
                                exact bytes sent, so a file produced in evidence later can be checked
                                rather than taken on trust.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="font-display text-display-s text-ink">Already taken</h2>

                            {recent.length === 0 ? (
                                <p className="mt-2 text-ui text-muted">
                                    Nothing has been exported from this mandate yet.
                                </p>
                            ) : (
                                <ul className="mt-3 flex flex-col gap-2">
                                    {recent.map((taken) => (
                                        <li
                                            key={taken.id}
                                            className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 rounded-sm border border-rule p-3"
                                        >
                                            <span className="flex flex-col gap-0.5">
                                                <span className="text-ui text-ink">
                                                    {taken.format?.toUpperCase() ?? 'file'}
                                                    {taken.h3 !== null && ` · ${taken.h3}`}
                                                    {taken.acceptedOnly === false && ' · all records'}
                                                </span>
                                                <span className="numeric-mono text-label break-all text-faint">
                                                    {taken.sha256 ?? ''}
                                                </span>
                                            </span>
                                            <span className="numeric-mono text-label text-muted">
                                                {taken.rows?.toLocaleString() ?? '0'} rows ·{' '}
                                                {bytes(taken.bytes)} · {taken.by ?? 'unknown'} ·{' '}
                                                {new Date(taken.takenAt).toLocaleString()}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </>
                )}
            </div>
        </div>
    );
}
