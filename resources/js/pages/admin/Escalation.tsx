import { Head, Link, useForm } from '@inertiajs/react';
import { BuildingMassing, BuildingSection, type Footprint } from '@/components/BuildingSection';
import { Button } from '@/components/Button';
import { ConfidenceMeter } from '@/components/ConfidenceMeter';
import { ConsoleShell } from '@/components/ConsoleShell';
import { PresenceMark, type TracePoint } from '@/components/PresenceMark';
import { cx } from '@/lib/cx';

interface Reading {
    signal: string;
    verdict: 'ok' | 'warn' | 'fail' | 'unknown';
    message: string;
    weight: number;
    deduction: number;
}

interface Record_ {
    id: number;
    score: number | null;
    status: string;
    observedAt: string;
    structureType: string;
    floors: number | null;
    unitCount: number | null;
    footprint: Footprint | null;
    signals: Partial<Record<'presence' | 'identification' | 'plausibility', Reading[]>>;
    presence: {
        fixCount: number;
        meanAccuracyM: number | null;
        worstAccuracyM: number | null;
        walkedM: number | null;
        integrityVerdict: string;
    } | null;
    trace: TracePoint[];
    photographs: Array<{
        id: number;
        kind: string;
        url: string | null;
        fromDeviceCamera: boolean | null;
    }>;
    enterprises: Array<{
        id: number;
        tradingName: string;
        unitLabel: string | null;
        floor: number | null;
    }>;
    officer: { id: number; name: string; captures: number; returnRate: number | null };
    place: { ward: string | null; lga: string | null; plusCode: string | null; h3: string | null };
}

/**
 * One escalation, with the evidence that raised it.
 *
 * Assembled by the same action the supervisor's review screen uses, so an admin
 * rules on exactly what the accuser saw. Deciding on a thinner view than the
 * person who raised the concern would be the wrong way round.
 */
export default function Escalation({ record }: { record: Record_ }) {
    const form = useForm({ outcome: 'dismissed', note: '' });

    const submit = (outcome: 'upheld' | 'dismissed') => {
        form.transform((data) => ({ ...data, outcome }));
        form.post(`/admin/escalations/${String(record.id)}`, { preserveScroll: true });
    };

    const readings = [
        ...(record.signals.presence ?? []),
        ...(record.signals.identification ?? []),
        ...(record.signals.plausibility ?? []),
    ];

    return (
        <ConsoleShell current="escalations">
            <Head title={`Escalation: ${record.structureType}`} />

            <div className="mx-auto max-w-[1100px] px-6 pb-32">
                <header className="mt-8 border-b border-rule pb-3">
                    <p className="text-label font-semibold tracking-[0.05em] text-gold uppercase">
                        <Link href="/admin/escalations" className="underline underline-offset-2">
                            Escalations
                        </Link>
                    </p>
                    <h1 className="font-display text-display-m text-ink">
                        {record.structureType.replace(/_/g, ' ')}
                    </h1>
                    <p className="mt-1 numeric-mono text-mono text-muted">
                        {record.place.plusCode ?? record.place.h3}
                        {record.place.ward !== null && ` · ${record.place.ward}`}
                    </p>
                </header>

                <div className="mt-6 grid gap-5 lg:grid-cols-[minmax(0,320px)_minmax(0,1fr)]">
                    <section className="flex flex-col gap-4 rounded-card border border-rule p-4 bg-raised">
                        <div className="flex flex-col items-center gap-2">
                            <PresenceMark
                                points={record.trace}
                                size={200}
                                tone={
                                    record.score !== null && record.score < 45 ? 'alert' : 'gold'
                                }
                                label={`The officer's trace, ${String(record.trace.length)} points`}
                            />
                            {record.presence !== null && (
                                <p className="numeric-mono text-mono text-muted">
                                    {record.presence.fixCount} fixes &middot;{' '}
                                    {record.presence.walkedM ?? 0} m walked
                                </p>
                            )}
                        </div>

                        <BuildingMassing footprint={record.footprint} floors={record.floors} size={180} />

                        <BuildingSection
                            floors={record.floors}
                            unitCount={record.unitCount}
                            units={record.enterprises}
                        />

                        <div className="border-t border-rule pt-3 text-label text-faint">
                            {record.officer.name} &middot; {record.officer.captures} captures
                            {record.officer.returnRate !== null &&
                                ` · ${String(record.officer.returnRate)}% returned`}
                        </div>
                    </section>

                    <section className="flex flex-col gap-4">
                        <div className="rounded-card border border-rule p-4 bg-raised">
                            <h2 className="mb-3 font-display text-display-s text-ink">
                                Every signal, as it was scored
                            </h2>
                            <ul className="flex flex-col gap-1.5">
                                {readings.map((reading) => (
                                    <li key={reading.signal} className="flex gap-2">
                                        <span
                                            className={cx(
                                                'numeric-mono w-6 shrink-0 text-mono font-semibold',
                                                reading.verdict === 'fail'
                                                    ? 'text-alert'
                                                    : reading.verdict === 'warn'
                                                      ? 'text-amber-ink'
                                                      : 'text-green',
                                            )}
                                        >
                                            {{ ok: 'ok', warn: '?', fail: '!', unknown: '--' }[
                                                reading.verdict
                                            ]}
                                        </span>
                                        <span
                                            className={cx(
                                                'text-ui',
                                                reading.verdict === 'fail'
                                                    ? 'text-alert'
                                                    : 'text-muted',
                                            )}
                                        >
                                            {reading.message}
                                        </span>
                                    </li>
                                ))}
                            </ul>

                            {/* An escalation is a question about somebody's
                                honesty. Ruling on it from a list of filenames
                                would be deciding on less than the accuser had. */}
                            <ul className="mt-3 grid grid-cols-3 gap-2 border-t border-rule pt-3">
                                {record.photographs.map((photo) => (
                                    <li key={photo.id} className="flex flex-col gap-1">
                                        {photo.url !== null && (
                                            <a
                                                href={photo.url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="block overflow-hidden rounded-card border border-rule bg-raised"
                                            >
                                                <img
                                                    src={photo.url}
                                                    alt={`${photo.kind} photographed at this capture`}
                                                    loading="lazy"
                                                    className="h-24 w-full bg-sunken object-cover"
                                                />
                                            </a>
                                        )}
                                        <span className="text-label text-muted">
                                            {photo.kind}
                                            {photo.fromDeviceCamera === true
                                                ? ' · camera'
                                                : ' · no metadata'}
                                        </span>
                                    </li>
                                ))}
                                {record.photographs.length === 0 && (
                                    <li className="col-span-3 text-ui text-alert">
                                        No photograph was attached.
                                    </li>
                                )}
                            </ul>
                        </div>

                        <div className="flex flex-col gap-4 rounded-card border border-rule p-4 bg-raised">
                            {record.score !== null && <ConfidenceMeter score={record.score} />}

                            <div>
                                <label
                                    htmlFor="note"
                                    className="text-label font-semibold tracking-[0.05em] text-muted uppercase"
                                >
                                    What you found
                                </label>
                                <p className="mt-1 mb-2 text-label text-faint">
                                    Required either way. This is a ruling about a person, and it
                                    is read back with their name on it.
                                </p>
                                <textarea
                                    id="note"
                                    value={form.data.note}
                                    onChange={(event) => {
                                        form.setData('note', event.target.value);
                                    }}
                                    rows={3}
                                    className="w-full rounded-sm border border-rule-strong bg-raised px-3 py-2.5 text-ui text-ink"
                                />
                                {form.errors.note !== undefined && (
                                    <p className="mt-1 text-label text-alert">{form.errors.note}</p>
                                )}
                                {form.errors.outcome !== undefined && (
                                    <p className="mt-1 text-label text-alert">
                                        {form.errors.outcome}
                                    </p>
                                )}
                            </div>

                            <div className="flex flex-wrap items-center gap-3">
                                <Button
                                    onClick={() => {
                                        submit('dismissed');
                                    }}
                                    disabled={form.processing}
                                >
                                    Dismiss, and accept the capture
                                </Button>
                                <Button
                                    variant="secondary"
                                    onClick={() => {
                                        submit('upheld');
                                    }}
                                    disabled={form.processing}
                                >
                                    Uphold, and send it back
                                </Button>
                            </div>

                            <p className="text-label text-faint">
                                Nothing is deleted either way. The ruling is appended to the log
                                beside the escalation it answers.
                            </p>
                        </div>
                    </section>
                </div>
            </div>
        </ConsoleShell>
    );
}
