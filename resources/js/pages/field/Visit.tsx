import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/Button';
import { GpsCard } from '@/components/CaptureChrome';
import { FieldShell } from '@/components/FieldShell';
import { compressPhotograph } from '@/lib/capture';
import { cx } from '@/lib/cx';
import type { OfficerDay } from '@/lib/fieldDay';
import { recordJobAction, retryJob, useJobActions } from '@/lib/offline/jobs';

type Angle = 'storefront' | 'signage' | 'interior' | 'other';

interface Visit {
    id: number;
    kind: 'site' | 'monitoring';
    dayNumber: number | null;
    days: number | null;
    status: string;
    reference: string | null;
    business: string | null;
    registration: string | null;
    registeredAddress: string | null;
    area: string | null;
    latitude: number;
    longitude: number;
    redo: string | null;
    checklist: { key: string; label: string; hint: string }[];
    arrivedAt: string | null;
    photos: Record<Angle, number>;
    submittedAt: string | null;
}

type Fix = { latitude: number; longitude: number; accuracy_m: number | null };

const ANGLES: { angle: Angle; label: string; note: string }[] = [
    { angle: 'storefront', label: 'Storefront', note: 'The front, from across the road. Needed.' },
    { angle: 'signage', label: 'Signage', note: 'The name, readable.' },
    { angle: 'interior', label: 'Inside', note: 'For the supervisor only.' },
    { angle: 'other', label: 'Other', note: 'Loading bay, second entrance.' },
];

/** The handset's position while this screen is open. No session is started; this is not a sweep. */
function usePosition(): Fix | null {
    const [fix, setFix] = useState<Fix | null>(null);

    useEffect(() => {
        if (!('geolocation' in navigator)) {
            return;
        }

        const id = navigator.geolocation.watchPosition(
            (p) => {
                setFix({ latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy_m: p.coords.accuracy });
            },
            () => undefined,
            { enableHighAccuracy: true, maximumAge: 5_000, timeout: 20_000 },
        );

        return () => {
            navigator.geolocation.clearWatch(id);
        };
    }, []);

    return fix;
}

/**
 * A verification visit somebody paid for through Enumerate. Go to the pin,
 * say you are there, photograph the front and the sign (and the inside, which
 * only the supervisor sees), answer five questions, file. Every step is kept
 * on the device and sent in order when there is signal, like an inspection.
 */
export default function VisitPage({ day, visit }: { day: OfficerDay; visit: Visit }) {
    const base = `/api/field/visits/${String(visit.id)}`;
    const position = usePosition();
    const actions = useJobActions(visit.id, base);
    const [answers, setAnswers] = useState<Record<string, { passed: boolean | null; detail: string }>>(() =>
        Object.fromEntries(visit.checklist.map((c) => [c.key, { passed: null, detail: '' }])),
    );
    const [notes, setNotes] = useState('');
    const daily = visit.kind === 'monitoring';
    const [log, setLog] = useState({ state: '' as '' | 'open' | 'low' | 'closed', opens: '', closes: '', staff: '', customers: '', activity: '' });
    const [busy, setBusy] = useState<Angle | null>(null);
    const [angle, setAngle] = useState<Angle>('storefront');
    const input = useRef<HTMLInputElement | null>(null);

    const arrived = visit.arrivedAt !== null || actions.some((a) => a.type === 'arrive');
    const taken = (a: Angle) => visit.photos[a] + actions.filter((x) => x.type === 'photo' && x.payload.angle === a).length;
    const reported = visit.submittedAt !== null || actions.some((a) => a.type === 'report');
    const waiting = actions.filter((a) => a.state === 'queued').length;
    const refused = actions.find((a) => a.state === 'failed');
    const photosTaken = ANGLES.reduce((sum, a) => sum + taken(a.angle), 0);
    const complete = daily
        ? log.state !== '' && log.activity.trim().length >= 10
        : visit.checklist.every((c) => answers[c.key]?.passed !== null);
    const ready = arrived && complete && (daily ? photosTaken > 0 : taken('storefront') > 0);

    const arrive = () => {
        if (position === null) {
            return;
        }

        void recordJobAction(visit.id, 'arrive', { longitude: position.longitude, latitude: position.latitude, accuracy_m: position.accuracy_m }, null, base);
    };

    const photograph = async (file: File, which: Angle) => {
        setBusy(which);

        try {
            const blob = await compressPhotograph(file);
            await recordJobAction(
                visit.id,
                'photo',
                { angle: which, ...(position === null ? {} : { device_longitude: position.longitude, device_latitude: position.latitude }) },
                blob,
                base,
            );
        } finally {
            setBusy(null);
        }
    };

    const submit = () => {
        if (daily) {
            const closed = log.state === 'closed';
            void recordJobAction(
                visit.id,
                'report',
                {
                    log: {
                        state: log.state,
                        opens: closed || log.opens === '' ? null : log.opens,
                        closes: closed || log.closes === '' ? null : log.closes,
                        staff: closed || log.staff === '' ? null : Number(log.staff),
                        customers: closed || log.customers === '' ? null : Number(log.customers),
                        activity: log.activity,
                    },
                    notes,
                },
                null,
                base,
            );

            return;
        }

        void recordJobAction(
            visit.id,
            'report',
            {
                answers: Object.fromEntries(visit.checklist.map((c) => [c.key, { passed: answers[c.key]?.passed === true, detail: answers[c.key]?.detail ?? '' }])),
                notes,
            },
            null,
            base,
        );
    };

    return (
        <FieldShell day={day} current="today" title={daily ? 'Daily visit' : 'Verification visit'}>
            <div className="mx-auto flex max-w-3xl flex-col gap-5">
                <section className="rounded-card border border-rule bg-raised px-5 py-5">
                    <p className="text-label font-extrabold tracking-[0.05em] text-gold-dark uppercase">
                        {daily ? `Daily visit · day ${String(visit.dayNumber)} of ${String(visit.days)}` : 'Verification visit'} · {visit.reference}
                    </p>
                    <h2 className="mt-1 font-display text-display-s text-ink">{visit.business}</h2>
                    {visit.registration !== null && <p className="numeric-mono text-table text-muted">CAC {visit.registration}</p>}
                    <p className="mt-2 text-ui text-ink">
                        <span className="font-bold">Registered address:</span> {visit.registeredAddress ?? 'not given by CAC'}
                    </p>
                    {visit.area !== null && <p className="text-ui text-muted">{visit.area}</p>}
                    <a
                        href={`https://www.google.com/maps/dir/?api=1&destination=${String(visit.latitude)},${String(visit.longitude)}`}
                        target="_blank"
                        rel="noreferrer"
                        className="mt-3 inline-flex min-h-touch items-center rounded-sm border border-rule-strong px-4 text-ui font-bold text-ink"
                    >
                        Directions to the pin <span aria-hidden="true">&nbsp;↗</span>
                    </a>
                    <p className="mt-2 text-table text-muted">
                        The supervisor placed the pin from the CAC address. If the business is somewhere else, find it and say so in the first check.
                    </p>
                </section>

                {visit.redo !== null && !reported && (
                    <p className="max-w-none rounded-sm bg-amber-soft px-4 py-3 text-ui text-amber-ink">
                        <span className="font-bold">Sent back:</span> {visit.redo}
                    </p>
                )}

                {reported ? (
                    <section className="rounded-card border border-green/30 bg-green-soft px-5 py-5">
                        <h2 className="font-display text-display-s text-ink">Report filed</h2>
                        <p className="mt-1 max-w-none text-ui text-ink">
                            {waiting > 0
                                ? `Kept on this device, ${String(waiting)} ${waiting === 1 ? 'item' : 'items'} to send when there is signal.`
                                : 'Sent. Your supervisor reviews it before the requester sees it.'}
                        </p>
                        <Link href="/field" className="mt-3 inline-block font-bold text-gold">
                            Back to Today
                        </Link>
                    </section>
                ) : (
                    <>
                        <section className="flex flex-col gap-3">
                            <GpsCard position={position} />
                            {arrived ? (
                                <p className="max-w-none rounded-sm bg-green-soft px-4 py-3 text-ui font-bold text-green">Arrival recorded.</p>
                            ) : (
                                <Button variant="primary" size="field-primary" fullWidth disabled={position === null} onClick={arrive}>
                                    {position === null ? 'Waiting for a position' : 'I have arrived'}
                                </Button>
                            )}
                        </section>

                        <section className={cx('rounded-card border border-rule bg-raised px-5 py-5', !arrived && 'opacity-60')}>
                            <h2 className="text-body font-extrabold text-ink">Photographs</h2>
                            <p className="mt-1 max-w-none text-table text-muted">
                                The requester sees the storefront and the signage. Keep people out of the frame. Inside shots stay with the supervisor.
                            </p>
                            <input
                                ref={input}
                                type="file"
                                accept="image/*"
                                capture="environment"
                                className="sr-only"
                                onChange={(e) => {
                                    const file = e.target.files?.[0];

                                    if (file !== undefined) {
                                        void photograph(file, angle);
                                    }

                                    e.target.value = '';
                                }}
                            />
                            <div className="mt-3 grid grid-cols-2 gap-2">
                                {ANGLES.map((a) => (
                                    <button
                                        key={a.angle}
                                        type="button"
                                        disabled={!arrived || busy !== null}
                                        onClick={() => {
                                            setAngle(a.angle);
                                            input.current?.click();
                                        }}
                                        className="flex min-h-touch-lg flex-col items-start justify-center rounded-sm border border-rule-strong bg-raised px-3 py-2 text-left disabled:opacity-60"
                                    >
                                        <span className="flex w-full items-center justify-between text-ui font-bold text-ink">
                                            + {a.label}
                                            <span className={cx('text-table', taken(a.angle) > 0 ? 'text-green' : 'text-faint')}>
                                                {busy === a.angle ? '…' : taken(a.angle)}
                                            </span>
                                        </span>
                                        <span className="text-[0.75rem] text-muted">{a.note}</span>
                                    </button>
                                ))}
                            </div>
                        </section>

                        {daily ? (
                            <section className={cx('rounded-card border border-rule bg-raised px-5 py-5', !arrived && 'opacity-60')}>
                                <h2 className="text-body font-extrabold text-ink">Today’s log</h2>
                                <div className="mt-3 grid grid-cols-3 gap-2" role="radiogroup" aria-label="How was the business">
                                    {(
                                        [
                                            ['open', 'Open, trading'],
                                            ['low', 'Open, quiet'],
                                            ['closed', 'Closed'],
                                        ] as const
                                    ).map(([key, label]) => (
                                        <button
                                            key={key}
                                            type="button"
                                            role="radio"
                                            disabled={!arrived}
                                            aria-checked={log.state === key}
                                            onClick={() => { setLog({ ...log, state: key }); }}
                                            className={cx(
                                                'min-h-touch rounded-sm border px-2 text-ui font-bold',
                                                log.state === key
                                                    ? key === 'open' ? 'border-green bg-green-soft text-green' : key === 'low' ? 'border-amber bg-amber-soft text-amber-ink' : 'border-graphite bg-graphite-soft text-ink'
                                                    : 'border-rule-strong bg-raised text-ink',
                                            )}
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>
                                {log.state !== 'closed' && (
                                    <div className="mt-4 grid grid-cols-2 gap-3">
                                        <label className="flex flex-col gap-1 text-table font-bold text-ink">
                                            Opened (seen)
                                            <input type="time" disabled={!arrived} value={log.opens} onChange={(e) => { setLog({ ...log, opens: e.target.value }); }} className="h-11 rounded-sm border border-rule-strong bg-raised px-3 text-ui font-normal" />
                                        </label>
                                        <label className="flex flex-col gap-1 text-table font-bold text-ink">
                                            Closed (seen)
                                            <input type="time" disabled={!arrived} value={log.closes} onChange={(e) => { setLog({ ...log, closes: e.target.value }); }} className="h-11 rounded-sm border border-rule-strong bg-raised px-3 text-ui font-normal" />
                                        </label>
                                        <label className="flex flex-col gap-1 text-table font-bold text-ink">
                                            Staff on site
                                            <input inputMode="numeric" disabled={!arrived} value={log.staff} onChange={(e) => { setLog({ ...log, staff: e.target.value.replace(/\D/g, '') }); }} className="h-11 rounded-sm border border-rule-strong bg-raised px-3 text-ui font-normal" />
                                        </label>
                                        <label className="flex flex-col gap-1 text-table font-bold text-ink">
                                            Customers seen
                                            <input inputMode="numeric" disabled={!arrived} value={log.customers} onChange={(e) => { setLog({ ...log, customers: e.target.value.replace(/\D/g, '') }); }} className="h-11 rounded-sm border border-rule-strong bg-raised px-3 text-ui font-normal" />
                                        </label>
                                    </div>
                                )}
                                <label className="mt-4 flex flex-col gap-1 text-table font-bold text-ink">
                                    What you saw
                                    <textarea
                                        rows={3}
                                        disabled={!arrived}
                                        maxLength={300}
                                        value={log.activity}
                                        onChange={(e) => { setLog({ ...log, activity: e.target.value }); }}
                                        placeholder="e.g. 3 supplier deliveries, steady walk-in customers. Stock visible on shelves."
                                        className="rounded-sm border border-rule-strong bg-raised p-3 text-ui font-normal"
                                    />
                                </label>
                                <p className="mt-1 text-[0.75rem] text-muted">The requester reads this line in their daily log.</p>
                            </section>
                        ) : (
                        <section className={cx('rounded-card border border-rule bg-raised px-5 py-5', !arrived && 'opacity-60')}>
                            <h2 className="text-body font-extrabold text-ink">Checks</h2>
                            <ul className="mt-3 flex list-none flex-col gap-4 p-0">
                                {visit.checklist.map((check) => {
                                    const answer = answers[check.key] ?? { passed: null, detail: '' };

                                    return (
                                        <li key={check.key}>
                                            <p className="text-ui font-bold text-ink">{check.label}</p>
                                            <div className="mt-2 flex gap-2">
                                                {([true, false] as const).map((value) => (
                                                    <button
                                                        key={String(value)}
                                                        type="button"
                                                        disabled={!arrived}
                                                        aria-pressed={answer.passed === value}
                                                        onClick={() => {
                                                            setAnswers({ ...answers, [check.key]: { ...answer, passed: value } });
                                                        }}
                                                        className={cx(
                                                            'min-h-touch flex-1 rounded-sm border text-ui font-bold',
                                                            answer.passed === value
                                                                ? value
                                                                    ? 'border-green bg-green-soft text-green'
                                                                    : 'border-alert bg-alert-soft text-alert-ink'
                                                                : 'border-rule-strong bg-raised text-ink',
                                                        )}
                                                    >
                                                        {value ? 'Yes' : 'No'}
                                                    </button>
                                                ))}
                                            </div>
                                            <input
                                                value={answer.detail}
                                                disabled={!arrived}
                                                maxLength={200}
                                                onChange={(e) => {
                                                    setAnswers({ ...answers, [check.key]: { ...answer, detail: e.target.value } });
                                                }}
                                                placeholder={check.hint}
                                                aria-label={`${check.label}: detail`}
                                                className="mt-2 h-11 w-full rounded-sm border border-rule-strong bg-raised px-3 text-ui text-ink"
                                            />
                                        </li>
                                    );
                                })}
                            </ul>
                        </section>
                        )}

                        <section className={cx('rounded-card border border-rule bg-raised px-5 py-5', !arrived && 'opacity-60')}>
                            <label htmlFor="notes" className="text-body font-extrabold text-ink">
                                Notes for the supervisor
                            </label>
                            <textarea
                                id="notes"
                                rows={3}
                                value={notes}
                                disabled={!arrived}
                                maxLength={2000}
                                onChange={(e) => {
                                    setNotes(e.target.value);
                                }}
                                className="mt-2 w-full rounded-sm border border-rule-strong bg-raised p-3 text-ui text-ink"
                            />
                        </section>

                        {refused !== undefined && (
                            <div className="rounded-sm bg-alert-soft px-4 py-3 text-ui text-alert-ink">
                                <p className="max-w-none font-bold">Not accepted: {refused.error}</p>
                                <button type="button" onClick={() => void retryJob(visit.id, base)} className="mt-1 font-bold underline">
                                    Try again
                                </button>
                            </div>
                        )}

                        <Button variant="primary" size="field-primary" fullWidth disabled={!ready} onClick={submit}>
                            {daily ? 'File today’s log' : 'File the report'}
                        </Button>
                        <p className="max-w-none text-center text-table text-muted">
                            {daily
                                ? photosTaken === 0 ? 'A photograph is needed first, even of a closed shutter. ' : ''
                                : taken('storefront') === 0 ? 'A storefront photograph is needed first. ' : ''}
                            Kept on this device and sent when there is signal.
                        </p>
                    </>
                )}
            </div>
        </FieldShell>
    );
}
