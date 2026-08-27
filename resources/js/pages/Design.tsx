import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { ConfidenceMeter } from '@/components/ConfidenceMeter';
import { DataTable, type Column } from '@/components/DataTable';
import { SelectField, TextField } from '@/components/Field';
import { CoverageBar, FootprintLegend, MapChrome } from '@/components/MapChrome';
import { PresenceMark } from '@/components/PresenceMark';
import { PriorObservation, Sheet, SheetSection } from '@/components/Sheet';
import { MoneyPanel } from '@/components/MoneyPanel';
import { StatusPill } from '@/components/StatusPill';
import { VerificationLadder } from '@/components/VerificationLadder';
import { SyncIndicator } from '@/components/SyncIndicator';
import { cx } from '@/lib/cx';
import type { Rung } from '@/lib/tiers';
import { captureStatus, cellStatus, type CellStatus } from '@/lib/status';
import { fabricatedTrace, walkedTrace } from '@/lib/trace';

/** A believable ladder: enumerated, identity checked, location not yet bought. */
const LADDER_MIXED: Rung[] = [
    { tier: 'listed', state: 'current', establishedOn: 'Mar 2026' },
    { tier: 'identity_verified', state: 'current', establishedOn: 'Mar 2026' },
    { tier: 'location_verified', state: 'not_established' },
    { tier: 'operations_verified', state: 'not_established' },
    { tier: 'monitored', state: 'not_established' },
];

/** One rung in each state, so the vocabulary can be read in one place. */
const LADDER_STATES: Rung[] = [
    { tier: 'listed', state: 'current', establishedOn: 'Mar 2026' },
    { tier: 'identity_verified', state: 'ageing', establishedOn: 'Aug 2024', elapsed: '18 months' },
    { tier: 'location_verified', state: 'stale', establishedOn: 'Jan 2023', elapsed: 'over 3 years' },
    { tier: 'operations_verified', state: 'pending', queueAhead: 3 },
    { tier: 'monitored', state: 'not_established' },
];

type Mode = 'daylight' | 'dusk';

const MODES: ReadonlyArray<{ value: Mode; label: string }> = [
    { value: 'daylight', label: 'Daylight' },
    { value: 'dusk', label: 'Dusk' },
];

interface SpecProps {
    n: string;
    title: string;
    note?: string;
    children: ReactNode;
}

function Spec({ n, title, note, children }: SpecProps) {
    return (
        <section className="mt-14 first:mt-0">
            <div className="mb-5 flex flex-wrap items-baseline gap-3 border-b border-ink pb-2">
                <span className="numeric-mono text-mono font-semibold text-gold">{n}</span>
                <h2 className="font-display text-display-m text-ink">{title}</h2>
                {note !== undefined && (
                    <span className="ml-auto text-label tracking-[0.1em] text-faint uppercase">
                        {note}
                    </span>
                )}
            </div>
            {children}
        </section>
    );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-wrap items-center gap-4 border-b border-rule py-3 last:border-b-0">
            <span className="w-40 shrink-0 text-label font-semibold tracking-[0.12em] text-faint uppercase">
                {label}
            </span>
            <div className="flex flex-wrap items-center gap-3">{children}</div>
        </div>
    );
}

interface Swatch {
    name: string;
    daylight: string;
    dusk: string;
    meaning: string;
    ratio: string;
}

const SWATCHES: readonly Swatch[] = [
    { name: 'gold', daylight: '#7E642E', dusk: '#D0AE63', meaning: 'Verification, active, emphasis', ratio: '4.70 / 7.97' },
    { name: 'green', daylight: '#2A6555', dusk: '#5CC0A4', meaning: 'Confirmed, complete', ratio: '5.70 / 7.66' },
    { name: 'amber', daylight: '#9A5913', dusk: '#EE9C45', meaning: 'Needs review, low confidence', ratio: '4.62 / 7.62' },
    { name: 'alert', daylight: '#8F2721', dusk: '#F2938C', meaning: 'Rejected, conflict, failure', ratio: '7.11 / 7.50' },
    { name: 'graphite', daylight: '#54646F', dusk: '#A2B0BB', meaning: 'Secondary text, chrome, hairlines', ratio: '5.14 / 7.61' },
];

interface QueueRow {
    id: string;
    officer: string;
    cell: string;
    structures: number;
    confidence: number;
    status: CellStatus;
    seed: number;
}

const QUEUE: readonly QueueRow[] = [
    { id: '1', officer: 'A. Bello', cell: '8928308280fffff', structures: 47, confidence: 88, status: 'submitted', seed: 32607 },
    { id: '2', officer: 'C. Okafor', cell: '8928308281bffff', structures: 52, confidence: 72, status: 'in_progress', seed: 4522 },
    { id: '3', officer: 'H. Suleiman', cell: '8928308283bffff', structures: 61, confidence: 21, status: 'returned', seed: 19455 },
    { id: '4', officer: 'M. Adeyemi', cell: '89283082847ffff', structures: 38, confidence: 94, status: 'accepted', seed: 23291 },
];

export default function Design() {
    const [mode, setMode] = useState<Mode>('daylight');

    // The surface mode belongs on the root element. body takes its background from
    // :root, so setting it on a wrapper leaves the viewport painted in the other
    // mode and the page ends up with a seam at its edges.
    useEffect(() => {
        document.documentElement.setAttribute('data-mode', mode);

        return () => {
            document.documentElement.removeAttribute('data-mode');
        };
    }, [mode]);
    const walked = walkedTrace(32607);
    const fabricated = fabricatedTrace();

    const columns: ReadonlyArray<Column<QueueRow>> = [
        {
            key: 'mark',
            header: 'Trace',
            width: '58px',
            render: (row) => (
                <PresenceMark
                    points={row.confidence < 40 ? fabricated : walkedTrace(row.seed)}
                    size={26}
                    tone={row.confidence < 40 ? 'alert' : 'gold'}
                    showCapturePoint={false}
                />
            ),
        },
        { key: 'officer', header: 'Officer', render: (row) => row.officer },
        {
            key: 'cell',
            header: 'Cell',
            render: (row) => <span className="numeric-mono text-mono">{row.cell}</span>,
        },
        { key: 'structures', header: 'Structures', numeric: true, render: (row) => row.structures },
        { key: 'confidence', header: 'Confidence', numeric: true, render: (row) => row.confidence },
        {
            key: 'status',
            header: 'Status',
            render: (row) => {
                const { tone, label } = cellStatus(row.status);

                return <StatusPill tone={tone} label={label} size="sm" />;
            },
        },
    ];

    return (
        <div className="min-h-dvh bg-surface text-ink">
            <Head title="Design system" />

            <div className="mx-auto max-w-6xl px-6 pb-24">
                {/* Drawing title block, the vernacular of a survey document. */}
                <header className="mt-9 border-[1.5px] border-ink">
                    <div className="border-b border-ink px-6 pt-6 pb-5">
                        <p className="mb-3 text-label font-semibold tracking-[0.16em] text-gold uppercase">
                            M1 / design system
                        </p>
                        <h1 className="font-display text-display-l text-ink">GeoVerify</h1>
                        <p className="mt-2 max-w-[52ch] font-display text-display-s text-muted italic">
                            An instrument, not a dashboard. Every state of every primitive, in
                            both modes.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-4 px-6 py-3">
                        <dl className="flex flex-wrap gap-x-10 gap-y-2">
                            {[
                                ['Display', 'Newsreader'],
                                ['UI', 'IBM Plex Sans'],
                                ['Machine', 'IBM Plex Mono'],
                            ].map(([k, v]) => (
                                <div key={k}>
                                    <dt className="text-label font-semibold tracking-[0.13em] text-faint uppercase">
                                        {k}
                                    </dt>
                                    <dd className="numeric-mono text-mono text-ink">{v}</dd>
                                </div>
                            ))}
                        </dl>

                        <div
                            className="flex items-center gap-1 rounded-sm border border-rule-strong p-1"
                            role="group"
                            aria-label="Surface mode"
                        >
                            {MODES.map(({ value, label }) => (
                                <button
                                    key={value}
                                    type="button"
                                    onClick={() => {
                                        setMode(value);
                                    }}
                                    aria-pressed={mode === value}
                                    className={cx(
                                        'rounded-[2px] px-3 py-1.5 text-ui font-medium',
                                        mode === value
                                            ? 'bg-gold text-on-accent'
                                            : 'text-muted hover:text-ink',
                                    )}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    </div>
                </header>

                <Spec n="01" title="Palette" note="measured, both surfaces">
                    <p className="mb-4 text-body text-muted">
                        Each status exists in both modes with the same hue and the same meaning,
                        tuned to stay legible on the surface behind it. Ratios are quoted daylight
                        first, against the raised surface in each case, which is the stricter test.
                    </p>
                    <div className="overflow-x-auto rounded-sm border border-rule-strong">
                        <table className="w-full border-collapse text-table">
                            <thead>
                                <tr>
                                    {['Token', 'Daylight', 'Dusk', 'Means', 'Contrast'].map((h) => (
                                        <th
                                            key={h}
                                            className="border-b border-ink px-3 py-2 text-left text-label font-semibold tracking-[0.12em] text-muted uppercase"
                                        >
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {SWATCHES.map((s) => (
                                    <tr key={s.name} className="border-b border-rule last:border-b-0">
                                        <td className="px-3 py-2 font-semibold">{s.name}</td>
                                        <td className="px-3 py-2">
                                            <span className="flex items-center gap-2">
                                                <span
                                                    className="size-6 rounded-[2px] border border-rule"
                                                    style={{ background: s.daylight }}
                                                />
                                                <span className="numeric-mono text-mono">{s.daylight}</span>
                                            </span>
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className="flex items-center gap-2">
                                                <span
                                                    className="size-6 rounded-[2px] border border-rule"
                                                    style={{ background: s.dusk }}
                                                />
                                                <span className="numeric-mono text-mono">{s.dusk}</span>
                                            </span>
                                        </td>
                                        <td className="px-3 py-2 text-muted">{s.meaning}</td>
                                        <td className="px-3 py-2 numeric-mono text-mono text-muted">
                                            {s.ratio}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Spec>

                <Spec n="02" title="Typography" note="three faces">
                    <div className="rounded-sm border border-rule-strong">
                        {[
                            ['display-l', 'font-display text-display-l', '381 of 412 structures'],
                            ['display-m', 'font-display text-display-m', 'Review queue'],
                            ['display-s', 'font-display text-display-s', 'Cell 8928308280fffff'],
                            ['body', 'text-body', 'Officer walked 2.4 km in this cell'],
                            ['ui', 'text-ui', 'Save capture'],
                            ['table', 'text-table', 'Mama Ngozi Provisions'],
                            ['label', 'text-label font-semibold uppercase tracking-[0.12em]', 'Occupancy status'],
                            ['mono', 'numeric-mono text-mono', '9.05785, 7.49508  +/- 4.2 m'],
                        ].map(([name, klass, sample]) => (
                            <div
                                key={name}
                                className="flex flex-wrap items-baseline gap-4 border-b border-rule px-4 py-3 last:border-b-0"
                            >
                                <span className="w-24 shrink-0 numeric-mono text-label text-faint">
                                    {name}
                                </span>
                                <span className={cx(klass, 'text-ink')}>{sample}</span>
                            </div>
                        ))}
                    </div>
                    <p className="mt-4 text-body text-muted">
                        Tabular figures are set on the root, not opted into per component. A
                        proportional figure in a coordinate column is a defect, so the safe
                        behaviour is the default.
                    </p>
                </Spec>

                <Spec n="03" title="Status" note="hue plus shape plus word">
                    <div className="rounded-sm border border-rule-strong px-4">
                        <Row label="Outline">
                            {(['unassigned', 'in_progress', 'submitted', 'accepted', 'returned'] as const).map(
                                (s) => {
                                    const { tone, label } = cellStatus(s);

                                    return <StatusPill key={s} tone={tone} label={label} />;
                                },
                            )}
                        </Row>
                        <Row label="Filled">
                            {(['draft', 'submitted', 'accepted', 'flagged', 'rejected'] as const).map((s) => {
                                const { tone, label } = captureStatus(s);

                                return <StatusPill key={s} tone={tone} label={label} emphasis="filled" />;
                            })}
                        </Row>
                        <Row label="Small">
                            {(['accepted', 'flagged', 'rejected'] as const).map((s) => {
                                const { tone, label } = captureStatus(s);

                                return <StatusPill key={s} tone={tone} label={label} size="sm" />;
                            })}
                        </Row>
                    </div>
                    <p className="mt-4 text-body text-muted">
                        Five tones, each with one fixed shape. A tone says what attention a row
                        needs and the word says the exact state, so In progress and Submitted
                        share a shape but never a label. Nothing depends on hue alone, so the
                        table still reads greyscale in a printed evidence pack.
                    </p>
                </Spec>

                <Spec n="04" title="Buttons" note="console 32px, field 48 to 52px">
                    <div className="rounded-sm border border-rule-strong px-4">
                        <Row label="Console">
                            <Button variant="primary">Accept capture</Button>
                            <Button variant="secondary">Return with reason</Button>
                            <Button variant="quiet">Skip</Button>
                            <Button variant="destructive">Revoke device</Button>
                        </Row>
                        <Row label="Disabled">
                            <Button variant="primary" disabled>
                                Accept capture
                            </Button>
                            <Button variant="secondary" disabled>
                                Return with reason
                            </Button>
                        </Row>
                        <Row label="Busy">
                            <Button variant="primary" busy>
                                Sending
                            </Button>
                            <Button variant="secondary" busy>
                                Building pack
                            </Button>
                        </Row>
                        <Row label="Field">
                            <Button variant="primary" size="field-primary">
                                Capture this building
                            </Button>
                            <Button variant="secondary" size="field">
                                Add point
                            </Button>
                        </Row>
                    </div>
                </Spec>

                <Spec n="05" title="Fields" note="every message state">
                    <div className="grid gap-6 rounded-sm border border-rule-strong p-4 sm:grid-cols-2 lg:grid-cols-3">
                        <TextField label="Trading name" defaultValue="Mama Ngozi Provisions" />
                        <TextField
                            label="Years at location"
                            defaultValue="6"
                            hint="Ask the operator, do not estimate."
                        />
                        <TextField
                            label="Phone"
                            defaultValue="080"
                            error="A Nigerian mobile number has 11 digits. Add the remaining 8."
                        />
                        <TextField
                            label="Capture point"
                            defaultValue="9.05785, 7.49508"
                            machine
                            readOnly
                            hint="Resolved server side. Not editable."
                        />
                        <SelectField label="Occupancy" defaultValue="occupied">
                            <option value="occupied">Occupied</option>
                            <option value="vacant">Vacant</option>
                            <option value="under_construction">Under construction</option>
                            <option value="refused">Refused</option>
                        </SelectField>
                        <TextField label="Registered name" placeholder="If different from trading name" disabled />
                        <div className="sm:col-span-2 lg:col-span-3">
                            <TextField label="Unit label" defaultValue="G03" size="field" hint="Field sizing, 48px minimum target." />
                        </div>
                    </div>
                </Spec>

                <Spec n="06" title="Sync state" note="the question answered before it is asked">
                    <div className="rounded-sm border border-rule-strong px-4">
                        <Row label="Online, clear">
                            <SyncIndicator connectivity="online" queued={0} lastSync="14:02" />
                        </Row>
                        <Row label="Sending">
                            <SyncIndicator connectivity="syncing" queued={12} lastSync="13:47" />
                        </Row>
                        <Row label="Offline, backlog">
                            <SyncIndicator connectivity="offline" queued={47} lastSync="11:20" />
                        </Row>
                        <Row label="Offline, clear">
                            <SyncIndicator connectivity="offline" queued={0} lastSync="14:02" />
                        </Row>
                        <Row label="Never synced">
                            <SyncIndicator connectivity="offline" queued={3} lastSync={null} />
                        </Row>
                    </div>
                    <p className="mt-4 text-body text-muted">
                        Offline is a normal state in the field, not an error, so it is never styled
                        as one. Amber appears only when work is actually waiting: an officer offline
                        with an empty queue has lost nothing, and the interface says so.
                    </p>
                </Spec>

                <Spec n="07" title="The Presence Mark" note="the signature">
                    <div className="rounded-sm border border-rule-strong bg-raised p-6">
                        <div className="flex flex-wrap items-end justify-center gap-10">
                            {[16, 40, 92, 184].map((size) => (
                                <div key={size} className="text-center">
                                    <PresenceMark
                                        points={walked}
                                        size={size}
                                        showCapturePoint={size >= 40}
                                        animate={size === 184}
                                    />
                                    <div className="mt-2 numeric-mono text-label text-faint">
                                        {size} px
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="mt-4 grid gap-0 rounded-sm border border-rule-strong sm:grid-cols-2">
                        <div className="border-b border-rule-strong p-5 sm:border-r sm:border-b-0">
                            <div className="mb-3 flex items-center gap-2 text-label font-semibold tracking-[0.1em] text-green uppercase">
                                <svg width="9" height="9" viewBox="0 0 10 10" aria-hidden="true">
                                    <circle cx="5" cy="5" r="4" fill="currentColor" />
                                </svg>
                                Walked
                            </div>
                            <div className="flex items-center gap-4">
                                <PresenceMark points={walked} size={104} tone="green" />
                                <p className="text-ui text-muted">
                                    Street legs, dwell clusters at each capture, jitter between
                                    fixes.
                                    <br />
                                    <span className="numeric-mono text-mono text-ink">
                                        confidence 88
                                    </span>
                                </p>
                            </div>
                        </div>
                        <div className="p-5">
                            <div className="mb-3 flex items-center gap-2 text-label font-semibold tracking-[0.1em] text-alert uppercase">
                                <svg width="9" height="9" viewBox="0 0 10 10" aria-hidden="true">
                                    <path d="M5 0.4 9.6 5 5 9.6 0.4 5z" fill="currentColor" />
                                </svg>
                                Fabricated
                            </div>
                            <div className="flex items-center gap-4">
                                <PresenceMark points={fabricated} size={104} tone="alert" />
                                <p className="text-ui text-muted">
                                    Straight segments, uniform spacing, no dwell, no jitter.
                                    <br />
                                    <span className="numeric-mono text-mono text-ink">
                                        confidence 21, flagged
                                    </span>
                                </p>
                            </div>
                        </div>
                    </div>
                </Spec>

                <Spec n="08" title="Confidence" note="surfaces suspicion, never adjudicates">
                    <div className="grid gap-5 sm:grid-cols-3">
                        <div className="rounded-sm border border-rule-strong p-4">
                            <ConfidenceMeter score={94} />
                        </div>
                        <div className="rounded-sm border border-rule-strong p-4">
                            <ConfidenceMeter
                                score={72}
                                flags={[
                                    { id: 'a', severity: 'deduction', message: 'Accuracy above 5 m on 2 fixes' },
                                    { id: 'b', severity: 'deduction', message: 'Capture interval suspiciously uniform' },
                                ]}
                            />
                        </div>
                        <div className="rounded-sm border border-rule-strong p-4">
                            <ConfidenceMeter
                                score={21}
                                flags={[
                                    { id: 'c', severity: 'hard', message: 'Mock location flag set on 6 fixes' },
                                    { id: 'd', severity: 'hard', message: 'Position jump of 4.2 km in 40 seconds' },
                                    { id: 'e', severity: 'deduction', message: 'Trace has no jitter' },
                                ]}
                            />
                        </div>
                    </div>
                </Spec>

                <Spec n="09" title="Table" note="the console's primary interface">
                    <DataTable
                        columns={columns}
                        rows={QUEUE}
                        rowKey={(row) => row.id}
                        caption="Review queue, ordered by confidence ascending"
                        onRowActivate={() => undefined}
                    />
                    <div className="mt-5">
                        <DataTable
                            columns={columns}
                            rows={[]}
                            rowKey={(row) => row.id}
                            caption="Empty state"
                            empty={
                                <>
                                    <span className="text-ink">Nothing is waiting for review.</span>
                                    <br />
                                    Captures appear here as officers sync.
                                </>
                            }
                        />
                    </div>
                </Spec>

                <Spec n="10" title="Field surfaces" note="map chrome and detail sheet">
                    <div className="grid gap-6 lg:grid-cols-2">
                        <div className="mx-auto w-full max-w-[380px]">
                            <div className="mb-2 text-label font-semibold tracking-[0.12em] text-faint uppercase">
                                Capture screen
                            </div>
                            <div className="h-[560px]">
                                <MapChrome
                                    cellId="8928308280fffff"
                                    openFlags={2}
                                    sync={
                                        <SyncIndicator
                                            connectivity="offline"
                                            queued={47}
                                            lastSync="14:02"
                                            compact
                                        />
                                    }
                                    coverage={<CoverageBar captured={381} detected={412} />}
                                    actions={
                                        <div className="flex flex-col gap-2.5">
                                            <Button variant="primary" size="field-primary" fullWidth>
                                                Capture this building
                                            </Button>
                                            <div className="flex gap-2.5">
                                                <Button variant="secondary" size="field" fullWidth>
                                                    Add point
                                                </Button>
                                                <Button variant="secondary" size="field" fullWidth>
                                                    Not a building
                                                </Button>
                                            </div>
                                        </div>
                                    }
                                >
                                    <div className="flex h-full flex-col items-center justify-center gap-4 p-4">
                                        <PresenceMark points={walked} size={150} />
                                        <FootprintLegend className="justify-center" />
                                        <p className="text-center text-label text-faint">
                                            MapLibre with PMTiles lands here at M4
                                        </p>
                                    </div>
                                </MapChrome>
                            </div>
                        </div>

                        <div className="mx-auto w-full max-w-[380px]">
                            <div className="mb-2 text-label font-semibold tracking-[0.12em] text-faint uppercase">
                                Structure detail sheet
                            </div>
                            <div className="h-[560px]">
                                <Sheet
                                    title="Structure"
                                    meta="9.05785, 7.49508   +/- 4.2 m"
                                    footer={
                                        <Button variant="primary" size="field-primary" fullWidth>
                                            Save capture
                                        </Button>
                                    }
                                    footerNote="Saved on device. Syncs when there is signal."
                                >
                                    <SheetSection label="Enterprises" trailing="8 / 14">
                                        <ul className="flex flex-col gap-2">
                                            {[
                                                ['G01', 'Mama Ngozi Provisions', '47.11 Retail', true],
                                                ['G02', 'Chidi POS', '64.19 Financial', true],
                                                ['G03', null, 'Not captured', false],
                                            ].map(([unit, name, sector, done]) => (
                                                <li
                                                    key={String(unit)}
                                                    className="flex items-center justify-between gap-3 rounded-sm border border-rule px-3 py-2"
                                                >
                                                    <span className="min-w-0">
                                                        <span className="block truncate text-ui text-ink">
                                                            <span className="numeric-mono text-mono text-faint">
                                                                {String(unit)}
                                                            </span>{' '}
                                                            {name === null ? '' : String(name)}
                                                        </span>
                                                        <span className="text-label text-faint">
                                                            {String(sector)}
                                                        </span>
                                                    </span>
                                                    {done === true ? (
                                                        <StatusPill tone="accepted" label="Done" size="sm" />
                                                    ) : (
                                                        <Button variant="secondary" size="field-compact">Add</Button>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    </SheetSection>
                                    <PriorObservation
                                        observedOn="2026-03-14"
                                        summary="Occupied, 12 units, 2 floors. Recorded by A. Bello."
                                    />
                                </Sheet>
                            </div>
                        </div>
                    </div>
                </Spec>

                <Spec n="11" title="Portal surfaces" note="phase 2 · a register office, not a field instrument">
                    <p className="mb-6 max-w-[64ch] text-body text-muted">
                        The field app is an instrument for an officer working a grid for six hours.
                        The portal is a counter a shop owner walks up to once, having never used
                        anything like it, about to send us money. Same tokens, same type system,
                        same status meanings. Different density, different voice.
                    </p>

                    <Row label="Added tokens">
                        <span className="flex items-center gap-2">
                            <span className="h-6 w-6 rounded-sm border border-rule bg-held" />
                            <span className="text-ui text-muted">held</span>
                        </span>
                        <span className="flex items-center gap-2">
                            <span className="h-6 w-6 rounded-sm border-2 border-paper-edge" />
                            <span className="text-ui text-muted">paper-edge</span>
                        </span>
                        <span className="text-label text-faint">
                            daylight only, and nothing else
                        </span>
                    </Row>

                    <Row label="Added tone">
                        <StatusPill tone="held" label="Held" />
                        <StatusPill tone="held" label="Held" emphasis="filled" />
                        <span className="max-w-[42ch] text-label text-faint">
                            Taken, not yet earned. Neither accepted nor in review, because on a
                            money screen the difference is what is being bought.
                        </span>
                    </Row>

                    <Row label="Added step">
                        <span className="numeric-mono text-display-xl text-ink">&#8358;15,000</span>
                        <span className="text-label text-faint">
                            display-xl, for the one number a screen is about
                        </span>
                    </Row>

                    <div className="mt-8 grid gap-8 lg:grid-cols-2">
                        <div>
                            <p className="mb-3 text-label font-semibold tracking-[0.12em] text-faint uppercase">
                                The ladder &middot; full
                            </p>
                            <div className="rounded-sm border border-rule-strong bg-raised p-5">
                                <VerificationLadder rungs={LADDER_MIXED} />
                            </div>
                        </div>

                        <div className="flex flex-col gap-8">
                            <div>
                                <p className="mb-3 text-label font-semibold tracking-[0.12em] text-faint uppercase">
                                    The ladder &middot; compact
                                </p>
                                <div className="max-w-xs rounded-sm border border-rule-strong bg-raised p-4">
                                    <VerificationLadder rungs={LADDER_MIXED} density="compact" />
                                </div>
                            </div>

                            <div>
                                <p className="mb-3 text-label font-semibold tracking-[0.12em] text-faint uppercase">
                                    Every rung state
                                </p>
                                <div className="max-w-xs rounded-sm border border-rule-strong bg-raised p-4">
                                    <VerificationLadder rungs={LADDER_STATES} density="compact" />
                                </div>
                                <p className="mt-2 max-w-[42ch] text-label text-faint">
                                    Established, ageing, stale, in progress, not established. Shape
                                    carries the state as well as colour, and every rung says its
                                    state in words.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="mt-10 grid gap-8 lg:grid-cols-[380px_1fr]">
                        <div>
                            <p className="mb-3 text-label font-semibold tracking-[0.12em] text-faint uppercase">
                                Money, at 360px
                            </p>
                            <div className="w-[360px] max-w-full rounded-sm border-2 border-paper-edge bg-surface p-5">
                                <MoneyPanel
                                    terms={{
                                        feeNaira: 15000,
                                        buys: 'We send an officer to your shop and confirm it is there.',
                                        within: '10 working days',
                                        queueAhead: 3,
                                        establishes: 'Location',
                                        whatHappens:
                                            'An officer visits, records the position, photographs the front, and writes a report you can show anyone.',
                                    }}
                                >
                                    <Button fullWidth>Pay &#8358;15,000</Button>
                                </MoneyPanel>
                            </div>
                        </div>

                        <div className="max-w-[52ch] text-body text-muted">
                            <p>
                                One component, not a screen assembling parts. The fee, the window,
                                the queue, what a negative finding costs and what a missed window
                                refunds are one disclosure, and the parts must never come apart.
                            </p>
                            <p className="mt-4">
                                Everything sits above the action. No accordion, no
                                &ldquo;see terms&rdquo;, no asterisk, and the unwelcome sentence is
                                set at the same size as the welcome one. A customer surprised after
                                paying is a chargeback, and is right to be annoyed.
                            </p>
                            <p className="mt-4 text-faint">
                                The word escrow appears nowhere, including in state names. It is
                                regulated in Nigeria and we are not licensed for it. Funds are held,
                                then released.
                            </p>
                        </div>
                    </div>
                </Spec>
            </div>
        </div>
    );
}
