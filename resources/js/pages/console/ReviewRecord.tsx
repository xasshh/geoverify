import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { ConsoleShell } from '@/components/ConsoleShell';
import { Button } from '@/components/Button';
import { ConfidenceMeter } from '@/components/ConfidenceMeter';
import { PresenceMark, type TracePoint } from '@/components/PresenceMark';
import {
    BuildingMassing,
    BuildingSection,
    type Footprint,
} from '@/components/BuildingSection';
import { StatusPill } from '@/components/StatusPill';
import { captureStatus, type CaptureStatus } from '@/lib/status';
import { cx } from '@/lib/cx';

interface Reading {
    signal: string;
    verdict: 'ok' | 'warn' | 'fail' | 'unknown';
    message: string;
    weight: number;
    deduction: number;
    evidence: Record<string, unknown> | null;
}

interface Record_ {
    id: number;
    score: number | null;
    status: string;
    observedAt: string;
    structureType: string;
    occupancyStatus: string;
    floors: number | null;
    unitCount: number | null;
    notes: string | null;
    accuracyM: number | null;
    signals: Partial<Record<'presence' | 'identification' | 'plausibility', Reading[]>>;
    presence: {
        startedAt: string;
        endedAt: string | null;
        integrityVerdict: string;
        appVersion: string | null;
        fixCount: number;
        meanAccuracyM: number | null;
        worstAccuracyM: number | null;
        walkedM: number | null;
    } | null;
    trace: TracePoint[];
    photographs: Array<{
        id: number;
        kind: string;
        url: string | null;
        fromDeviceCamera: boolean | null;
        distanceM: number | null;
        capturedAt: string | null;
    }>;
    footprint: Footprint | null;
    enterprises: Array<{
        id: number;
        unitLabel: string | null;
        floor: number | null;
        tradingName: string;
        registeredName: string | null;
        sectorCode: string | null;
        scaleBand: string | null;
        employeeBand: string | null;
        operatingStatus: string;
        yearsAtLocation: number | null;
        signageObserved: boolean;
    }>;
    officer: {
        id: number;
        name: string;
        captures: number;
        meanConfidence: number | null;
        returnRate: number | null;
    };
    place: {
        h3: string | null;
        plusCode: string | null;
        ward: string | null;
        lga: string | null;
        latitude: number | null;
        longitude: number | null;
    };
}

interface ReviewRecordProps {
    record: Record_;
    canDecide: boolean;
}

const RETURN_REASONS = [
    'The trace does not show a walk to this building.',
    'The photographs do not show the structure recorded.',
    'The position is outside the assigned cell.',
    'The business details are incomplete.',
    'The structure is already recorded under another capture.',
];

/** A verdict, said in a word and marked in a shape, so it survives greyscale. */
function VerdictMark({ verdict }: { verdict: Reading['verdict'] }) {
    const mark = { ok: 'ok', warn: '?', fail: '!', unknown: '--' }[verdict];
    const tone = {
        ok: 'text-green',
        warn: 'text-amber',
        fail: 'text-alert',
        unknown: 'text-faint',
    }[verdict];

    return (
        <span className={cx('numeric-mono text-mono font-semibold shrink-0 w-6', tone)}>{mark}</span>
    );
}

/**
 * One of the three questions, with its readings underneath it.
 *
 * Every reading is shown, not only the flags. A column with nothing in it is
 * ambiguous between "clean" and "not checked", and a supervisor deciding whether
 * to return a person's day of work should never have to guess which.
 */
function Question({
    title,
    caption,
    readings,
    children,
}: {
    title: string;
    caption: string;
    readings: Reading[];
    children?: React.ReactNode;
}) {
    return (
        <section className="flex flex-col gap-3 rounded-sm border border-rule-strong p-4">
            <header>
                <h2 className="font-display text-display-s text-ink">{title}</h2>
                <p className="mt-0.5 text-label text-faint">{caption}</p>
            </header>

            {children}

            <ul className="flex flex-col gap-1.5 border-t border-rule pt-3">
                {readings.map((reading) => (
                    <li key={reading.signal} className="flex gap-1.5">
                        <VerdictMark verdict={reading.verdict} />
                        <span className="flex flex-col gap-0.5">
                            <span
                                className={cx(
                                    'text-ui',
                                    reading.verdict === 'fail'
                                        ? 'text-alert'
                                        : reading.verdict === 'warn'
                                          ? 'text-amber'
                                          : 'text-muted',
                                )}
                            >
                                {reading.message}
                            </span>
                            {reading.deduction > 0 && (
                                <span className="numeric-mono text-label text-faint">
                                    {reading.signal} took {reading.deduction} of {reading.weight}
                                </span>
                            )}
                        </span>
                    </li>
                ))}
                {readings.length === 0 && (
                    <li className="text-ui text-faint">Nothing was checked here.</li>
                )}
            </ul>
        </section>
    );
}

function Fact({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-3 border-b border-rule py-1.5 last:border-b-0">
            <span className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                {label}
            </span>
            <span className="numeric-mono text-mono text-ink">{value}</span>
        </div>
    );
}

/**
 * The review screen.
 *
 * The columns are the three questions in the order a supervisor actually asks
 * them: presence, then identification, then plausibility. Not map, photos, form.
 * Flags sit with the evidence that produced them rather than in a sidebar,
 * because "accuracy over 5 m on 2 fixes" means nothing next to a photograph and
 * everything next to the fix list.
 *
 * The officer's history is on the screen so a low score is judged against a
 * person's record rather than in isolation. A first bad day and a pattern are
 * not the same finding.
 */
export default function ReviewRecord({ record, canDecide }: ReviewRecordProps) {
    const [decision, setDecision] = useState<'accept' | 'return' | 'escalate'>('accept');
    const form = useForm({ decision: 'accept', reason: '', note: '' });
    const status = captureStatus(record.status as CaptureStatus);

    const submit = (chosen: 'accept' | 'return' | 'escalate') => {
        setDecision(chosen);
        form.transform((data) => ({ ...data, decision: chosen }));
        form.post(`/console/review/${String(record.id)}`, { preserveScroll: true });
    };

    return (
        <ConsoleShell current="review">
            <Head title={`Review: ${record.structureType}`} />

            <div className="mx-auto max-w-[1400px] px-6 pb-32">
                <header className="mt-8 flex flex-wrap items-baseline justify-between gap-4 border-b-[1.5px] border-ink pb-3">
                    <div>
                        <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
                            <Link href="/console/review" className="underline underline-offset-2">
                                Review queue
                            </Link>
                        </p>
                        <h1 className="font-display text-display-m text-ink">
                            {record.structureType}
                        </h1>
                        <p className="mt-1 numeric-mono text-mono text-muted">
                            {record.place.plusCode ?? record.place.h3}
                            {record.place.ward !== null && ` · ${record.place.ward}`}
                        </p>
                    </div>
                    <StatusPill tone={status.tone} label={status.label} emphasis="filled" />
                </header>

                {form.errors.decision !== undefined && (
                    <p className="mt-4 border-l-2 border-alert bg-raised px-4 py-2.5 text-ui text-alert">
                        {form.errors.decision}
                    </p>
                )}

                {/*
                    The building, before the three questions rather than inside
                    one of them. It is the thing being judged, not evidence about
                    it, and a supervisor who cannot picture the building is
                    reading the presence trace and the photographs blind.
                */}
                <section className="mt-6 rounded-sm border border-rule-strong p-4">
                    <header className="mb-3">
                        <h2 className="font-display text-display-s text-ink">The building</h2>
                        <p className="mt-0.5 text-label text-faint">
                            What was counted, drawn to the outline that was detected.
                        </p>
                    </header>

                    <div className="grid gap-6 lg:grid-cols-[minmax(0,240px)_minmax(0,780px)]">
                        <div className="flex flex-col gap-3">
                            <BuildingMassing footprint={record.footprint} floors={record.floors} />

                            <div className="flex flex-col">
                                <Fact
                                    label="Type"
                                    value={record.structureType.replace(/_/g, ' ')}
                                />
                                <Fact
                                    label="Occupancy"
                                    value={record.occupancyStatus.replace(/_/g, ' ')}
                                />
                                <Fact label="Storeys" value={record.floors ?? 'not recorded'} />
                                <Fact label="Units" value={record.unitCount ?? 'not recorded'} />
                            </div>
                        </div>

                        <BuildingSection
                            floors={record.floors}
                            unitCount={record.unitCount}
                            units={record.enterprises}
                        />
                    </div>
                </section>

                <div className="mt-6 grid gap-5 lg:grid-cols-3">
                    <Question
                        title="Presence"
                        caption="Was the officer there?"
                        readings={record.signals.presence ?? []}
                    >
                        <div className="flex flex-col items-center gap-2">
                            <PresenceMark
                                points={record.trace}
                                size={240}
                                tone={
                                    record.score !== null && record.score < 45 ? 'alert' : 'gold'
                                }
                                animate
                                label={`The officer's walking trace, ${String(record.trace.length)} points, with the capture point marked`}
                            />
                            {record.presence !== null && (
                                <p className="numeric-mono text-mono text-muted">
                                    {record.presence.fixCount} fixes ·{' '}
                                    {record.presence.walkedM ?? 0} m walked
                                </p>
                            )}
                        </div>

                        {record.presence !== null && (
                            <div className="flex flex-col">
                                <Fact
                                    label="Mean accuracy"
                                    value={`${String(record.presence.meanAccuracyM ?? 0)} m`}
                                />
                                <Fact
                                    label="Worst"
                                    value={`${String(record.presence.worstAccuracyM ?? 0)} m`}
                                />
                                <Fact label="Integrity" value={record.presence.integrityVerdict} />
                            </div>
                        )}
                    </Question>

                    <Question
                        title="Identification"
                        caption="Is this the thing they say it is?"
                        readings={record.signals.identification ?? []}
                    >
                        {/*
                            The photographs themselves. This column asks whether
                            the record is of the thing it claims to be, and a
                            list of the word "facade" cannot answer that: it is
                            an assertion where the evidence should be. Opening
                            one in a new tab gives the full size behind the same
                            short lived signature.
                        */}
                        <ul className="grid grid-cols-2 gap-2">
                            {record.photographs.map((photo) => (
                                <li key={photo.id} className="flex flex-col gap-1">
                                    {photo.url === null ? (
                                        <span className="flex h-32 items-center justify-center rounded-sm border border-dashed border-alert px-2 text-center text-label text-alert">
                                            The file is missing from storage.
                                        </span>
                                    ) : (
                                        <a
                                            href={photo.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="block overflow-hidden rounded-sm border border-rule"
                                        >
                                            <img
                                                src={photo.url}
                                                alt={`${photo.kind} photographed at this capture`}
                                                loading="lazy"
                                                className="h-32 w-full bg-sunken object-cover"
                                            />
                                        </a>
                                    )}
                                    <span className="flex items-baseline justify-between gap-2">
                                        <span className="text-ui text-ink">{photo.kind}</span>
                                        <span className="numeric-mono text-label text-muted">
                                            {photo.fromDeviceCamera === true
                                                ? 'camera'
                                                : 'no metadata'}
                                            {photo.distanceM !== null &&
                                                ` · ${String(photo.distanceM)} m`}
                                        </span>
                                    </span>
                                </li>
                            ))}
                            {record.photographs.length === 0 && (
                                <li className="col-span-2 text-ui text-alert">
                                    No photograph was attached.
                                </li>
                            )}
                        </ul>

                        <div className="flex flex-col">
                            <Fact label="Cell" value={record.place.h3 ?? 'unknown'} />
                            {record.place.lga !== null && (
                                <Fact label="LGA" value={record.place.lga} />
                            )}
                        </div>
                    </Question>

                    <Question
                        title="Plausibility"
                        caption="Does the record hold together?"
                        readings={record.signals.plausibility ?? []}
                    >
                        <div className="flex flex-col gap-3">
                            {record.enterprises.map((enterprise) => (
                                <div
                                    key={enterprise.id}
                                    className="rounded-sm border border-rule p-3"
                                >
                                    <p className="text-ui font-semibold text-ink">
                                        {enterprise.tradingName}
                                    </p>
                                    <div className="mt-1 flex flex-col">
                                        <Fact
                                            label="Sector"
                                            value={enterprise.sectorCode ?? 'none'}
                                        />
                                        <Fact
                                            label="Scale"
                                            value={enterprise.scaleBand ?? 'none'}
                                        />
                                        <Fact
                                            label="Years"
                                            value={enterprise.yearsAtLocation ?? 'none'}
                                        />
                                        <Fact
                                            label="Signage"
                                            value={enterprise.signageObserved ? 'seen' : 'none'}
                                        />
                                    </div>
                                </div>
                            ))}
                            {record.enterprises.length === 0 && (
                                <p className="text-ui text-muted">
                                    No business was recorded at this structure.
                                </p>
                            )}
                        </div>

                        <div className="flex flex-col border-t border-rule pt-3">
                            <p className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                                Officer history
                            </p>
                            <Fact label="Captures" value={record.officer.captures} />
                            <Fact
                                label="Return rate"
                                value={
                                    record.officer.returnRate === null
                                        ? 'none'
                                        : `${String(record.officer.returnRate)}%`
                                }
                            />
                            <Fact
                                label="Mean confidence"
                                value={record.officer.meanConfidence ?? 'none'}
                            />
                        </div>
                    </Question>
                </div>

                {canDecide ? (
                    <div className="mt-6 flex flex-col gap-4 rounded-sm border border-rule-strong p-4">
                        {/* The score sits with the decision because that is
                            where it is weighed. Its flags are not repeated
                            here: they are pinned to the evidence that produced
                            them, three columns up, which is the whole point of
                            the layout. */}
                        {record.score === null ? (
                            <p className="text-ui text-muted">
                                Not scored yet. Decide on the evidence above, or wait for the
                                scorer to reach it.
                            </p>
                        ) : (
                            <ConfidenceMeter score={record.score} />
                        )}
                        <div>
                            <label
                                htmlFor="reason"
                                className="text-label font-semibold tracking-[0.12em] text-muted uppercase"
                            >
                                Reason (required to return or escalate)
                            </label>
                            <p className="mt-1 mb-2 text-label text-faint">
                                Written to the officer, and to the log. Say what was wrong so it can
                                be done differently.
                            </p>
                            <div className="flex flex-wrap gap-1.5 pb-2">
                                {RETURN_REASONS.map((reason) => (
                                    <button
                                        key={reason}
                                        type="button"
                                        onClick={() => {
                                            form.setData('reason', reason);
                                        }}
                                        className="rounded-sm border border-rule px-2 py-1 text-label text-muted hover:border-rule-strong hover:text-ink"
                                    >
                                        {reason}
                                    </button>
                                ))}
                            </div>
                            <textarea
                                id="reason"
                                value={form.data.reason}
                                onChange={(event) => {
                                    form.setData('reason', event.target.value);
                                }}
                                rows={2}
                                className="w-full rounded-sm border border-rule-strong bg-surface px-3 py-2 text-ui text-ink"
                            />
                            {form.errors.reason !== undefined && (
                                <p className="mt-1 text-label text-alert">{form.errors.reason}</p>
                            )}
                        </div>

                        <div className="flex flex-wrap gap-3">
                            <Button
                                onClick={() => {
                                    submit('accept');
                                }}
                                disabled={form.processing}
                            >
                                Accept
                            </Button>
                            <Button
                                variant="secondary"
                                onClick={() => {
                                    submit('return');
                                }}
                                disabled={form.processing}
                            >
                                Return to officer
                            </Button>
                            <Button
                                variant="secondary"
                                onClick={() => {
                                    submit('escalate');
                                }}
                                disabled={form.processing}
                            >
                                Escalate
                            </Button>
                            <p className="self-center text-label text-faint">
                                {decision === 'accept'
                                    ? 'Accepting enters this record into the register.'
                                    : 'Nothing is deleted. The decision is appended to the log.'}
                            </p>
                        </div>
                    </div>
                ) : (
                    <div className="mt-6 flex flex-col gap-4">
                        {record.score !== null && (
                            <div className="rounded-sm border border-rule-strong p-4">
                                <ConfidenceMeter score={record.score} />
                            </div>
                        )}
                        <p className="border-l-2 border-rule-strong bg-raised px-4 py-2.5 text-ui text-muted">
                            This capture has already been decided, or it is yours. Re-enumeration
                            creates a new observation; nothing here is overwritten.
                        </p>
                    </div>
                )}
            </div>
        </ConsoleShell>
    );
}
