import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/Button';
import { GpsCard } from '@/components/CaptureChrome';
import { FieldShell } from '@/components/FieldShell';
import { compressPhotograph } from '@/lib/capture';
import { cx } from '@/lib/cx';
import type { OfficerDay } from '@/lib/fieldDay';
import { recordJobAction, retryJob, useJobActions } from '@/lib/offline/jobs';

type Angle = 'site' | 'entrance' | 'other';
type Found = 'at_listed' | 'elsewhere' | 'not_found';

interface Check {
    id: number;
    status: string;
    code: string;
    name: string;
    description: string | null;
    listedPlace: string | null;
    latitude: number | null;
    longitude: number | null;
    redo: string | null;
    found: { key: Found; label: string }[];
    checklist: { key: string; label: string; options: { value: string; label: string }[]; hint: string }[];
    arrivedAt: string | null;
    photos: Record<Angle, number>;
    submittedAt: string | null;
}

type Fix = { latitude: number; longitude: number; accuracy_m: number | null };

const ANGLES: { angle: Angle; label: string; note: string }[] = [
    { angle: 'site', label: 'The unit', note: 'From outside, the whole place. Needed.' },
    { angle: 'entrance', label: 'The way in', note: 'Gate, steps or ramp.' },
    { angle: 'other', label: 'Other', note: 'Flooding, a hazard, the road.' },
];

/** The handset's position while this screen is open. */
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
 * A polling unit check. Go to where the list puts it (or find it), say you
 * are there, photograph it and the way in, answer ten questions, file. Every
 * step is kept on the device and sent in order when there is signal, like a
 * site visit. Where you stood when you pressed "I am at the polling unit"
 * becomes its confirmed position, so press it at the unit itself.
 */
export default function PollingUnitPage({ day, check }: { day: OfficerDay; check: Check }) {
    const base = `/api/field/polling-units/${String(check.id)}`;
    const position = usePosition();
    const actions = useJobActions(check.id, base);
    const [found, setFound] = useState<Found | null>(null);
    const [answers, setAnswers] = useState<Record<string, { answer: string | null; detail: string }>>(() =>
        Object.fromEntries(check.checklist.map((c) => [c.key, { answer: null, detail: '' }])),
    );
    const [notes, setNotes] = useState('');
    const [busy, setBusy] = useState<Angle | null>(null);
    const [angle, setAngle] = useState<Angle>('site');
    const input = useRef<HTMLInputElement | null>(null);

    const arrived = check.arrivedAt !== null || actions.some((a) => a.type === 'arrive');
    const taken = (a: Angle) => check.photos[a] + actions.filter((x) => x.type === 'photo' && x.payload.angle === a).length;
    const reported = check.submittedAt !== null || actions.some((a) => a.type === 'report');
    const waiting = actions.filter((a) => a.state === 'queued').length;
    const refused = actions.find((a) => a.state === 'failed');
    const missing = found !== 'not_found' ? check.checklist.filter((c) => answers[c.key]?.answer === null).length : 0;
    const complete = found !== null && (found === 'not_found' ? notes.trim().length >= 10 : missing === 0);
    const ready = arrived && complete && taken('site') > 0;

    const arrive = () => {
        if (position === null) {
            return;
        }

        void recordJobAction(check.id, 'arrive', { longitude: position.longitude, latitude: position.latitude, accuracy_m: position.accuracy_m }, null, base);
    };

    const photograph = async (file: File, which: Angle) => {
        setBusy(which);

        try {
            const blob = await compressPhotograph(file);
            await recordJobAction(
                check.id,
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
        if (found === null) {
            return;
        }

        void recordJobAction(
            check.id,
            'report',
            {
                found,
                answers:
                    found === 'not_found'
                        ? {}
                        : Object.fromEntries(check.checklist.map((c) => [c.key, { answer: answers[c.key]?.answer ?? null, detail: answers[c.key]?.detail ?? '' }])),
                notes,
            },
            null,
            base,
        );
    };

    return (
        <FieldShell day={day} current="today" title="Polling unit check">
            <div className="mx-auto flex max-w-3xl flex-col gap-5">
                <section className="rounded-card border border-rule bg-raised px-5 py-5">
                    <p className="text-label font-extrabold tracking-[0.05em] text-gold-dark uppercase">Polling unit check · {check.code}</p>
                    <h2 className="mt-1 font-display text-display-s text-ink">{check.name}</h2>
                    {check.listedPlace !== null && <p className="mt-1 text-ui text-muted">{check.listedPlace}</p>}
                    {check.description !== null && <p className="mt-2 text-ui text-ink">{check.description}</p>}
                    {check.latitude !== null && check.longitude !== null ? (
                        <a
                            href={`https://www.google.com/maps/dir/?api=1&destination=${String(check.latitude)},${String(check.longitude)}`}
                            target="_blank"
                            rel="noreferrer"
                            className="mt-3 inline-flex min-h-touch items-center rounded-sm border border-rule-strong px-4 text-ui font-bold text-ink"
                        >
                            Directions to where the list puts it <span aria-hidden="true">&nbsp;↗</span>
                        </a>
                    ) : (
                        <p className="mt-3 rounded-sm bg-amber-soft px-4 py-3 text-ui text-amber-ink">
                            The list has no position for this unit. Find it by its name and ask locally: that is what this check is for.
                        </p>
                    )}
                </section>

                {check.redo !== null && !reported && (
                    <p className="max-w-none rounded-sm bg-amber-soft px-4 py-3 text-ui text-amber-ink">
                        <span className="font-bold">Sent back:</span> {check.redo}
                    </p>
                )}

                {reported ? (
                    <section className="rounded-card border border-green/30 bg-green-soft px-5 py-5">
                        <h2 className="font-display text-display-s text-ink">Check filed</h2>
                        <p className="mt-1 max-w-none text-ui text-ink">
                            {waiting > 0
                                ? `Kept on this device, ${String(waiting)} ${waiting === 1 ? 'item' : 'items'} to send when there is signal.`
                                : 'Sent. Your supervisor reviews it before anyone else sees it.'}
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
                                <p className="max-w-none rounded-sm bg-green-soft px-4 py-3 text-ui font-bold text-green">Position recorded at the unit.</p>
                            ) : (
                                <>
                                    <Button variant="primary" size="field-primary" fullWidth disabled={position === null} onClick={arrive}>
                                        {position === null ? 'Waiting for a position' : 'I am at the polling unit'}
                                    </Button>
                                    <p className="max-w-none text-center text-table text-muted">
                                        Press this standing at the unit itself (or where it should be, if you cannot find it). This position goes on the map.
                                    </p>
                                </>
                            )}
                        </section>

                        <section className={cx('rounded-card border border-rule bg-raised px-5 py-5', !arrived && 'opacity-60')}>
                            <h2 className="text-body font-extrabold text-ink">Did you find it?</h2>
                            <div className="mt-3 flex flex-col gap-2" role="radiogroup" aria-label="Did you find it">
                                {check.found.map((f) => (
                                    <button
                                        key={f.key}
                                        type="button"
                                        role="radio"
                                        aria-checked={found === f.key}
                                        disabled={!arrived}
                                        onClick={() => { setFound(f.key); }}
                                        className={cx(
                                            'min-h-touch rounded-sm border px-4 text-left text-ui font-bold',
                                            found === f.key
                                                ? f.key === 'not_found' ? 'border-alert bg-alert-soft text-alert-ink' : f.key === 'elsewhere' ? 'border-amber bg-amber-soft text-amber-ink' : 'border-green bg-green-soft text-green'
                                                : 'border-rule-strong bg-raised text-ink',
                                        )}
                                    >
                                        {f.label}
                                    </button>
                                ))}
                            </div>
                            {found === 'elsewhere' && (
                                <p className="mt-2 text-table text-muted">Good. Make sure you pressed "I am at the polling unit" at the real place, so the map shows where it really is.</p>
                            )}
                        </section>

                        <section className={cx('rounded-card border border-rule bg-raised px-5 py-5', !arrived && 'opacity-60')}>
                            <h2 className="text-body font-extrabold text-ink">Photographs</h2>
                            <p className="mt-1 max-w-none text-table text-muted">Keep people out of the frame. The client sees the unit and the way in.</p>
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
                            <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-3">
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

                        {found !== 'not_found' && (
                            <section className={cx('rounded-card border border-rule bg-raised px-5 py-5', (!arrived || found === null) && 'opacity-60')}>
                                <h2 className="text-body font-extrabold text-ink">At the unit</h2>
                                <ul className="mt-3 flex list-none flex-col gap-5 p-0">
                                    {check.checklist.map((q) => {
                                        const a = answers[q.key] ?? { answer: null, detail: '' };

                                        return (
                                            <li key={q.key}>
                                                <p className="text-ui font-bold text-ink">{q.label}</p>
                                                <div className="mt-2 flex flex-wrap gap-2" role="radiogroup" aria-label={q.label}>
                                                    {q.options.map((o) => (
                                                        <button
                                                            key={o.value}
                                                            type="button"
                                                            role="radio"
                                                            aria-checked={a.answer === o.value}
                                                            disabled={!arrived || found === null}
                                                            onClick={() => { setAnswers({ ...answers, [q.key]: { ...a, answer: o.value } }); }}
                                                            className={cx(
                                                                'min-h-touch rounded-sm border px-3 text-ui font-bold',
                                                                a.answer === o.value ? 'border-gold bg-gold-soft text-gold-dark' : 'border-rule-strong bg-raised text-ink',
                                                            )}
                                                        >
                                                            {o.label}
                                                        </button>
                                                    ))}
                                                </div>
                                                <input
                                                    value={a.detail}
                                                    disabled={!arrived || found === null}
                                                    maxLength={200}
                                                    onChange={(e) => { setAnswers({ ...answers, [q.key]: { ...a, detail: e.target.value } }); }}
                                                    placeholder={q.hint}
                                                    aria-label={`${q.label}: detail`}
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
                                {found === 'not_found' ? 'Where you looked and who you asked (needed)' : 'Notes for the supervisor'}
                            </label>
                            <textarea
                                id="notes"
                                rows={3}
                                value={notes}
                                disabled={!arrived}
                                maxLength={2000}
                                onChange={(e) => { setNotes(e.target.value); }}
                                className="mt-2 w-full rounded-sm border border-rule-strong bg-raised p-3 text-ui text-ink"
                            />
                        </section>

                        {refused !== undefined && (
                            <div className="rounded-sm bg-alert-soft px-4 py-3 text-ui text-alert-ink">
                                <p className="max-w-none font-bold">Not accepted: {refused.error}</p>
                                <button type="button" onClick={() => void retryJob(check.id, base)} className="mt-1 font-bold underline">
                                    Try again
                                </button>
                            </div>
                        )}

                        <Button variant="primary" size="field-primary" fullWidth disabled={!ready} onClick={submit}>
                            File the check
                        </Button>
                        <p className="max-w-none text-center text-table text-muted">
                            {taken('site') === 0 ? 'A photograph of the unit is needed first. ' : ''}
                            {found !== null && found !== 'not_found' && missing > 0 ? `${String(missing)} ${missing === 1 ? 'question' : 'questions'} still to answer. ` : ''}
                            Kept on this device and sent when there is signal.
                        </p>
                    </>
                )}
            </div>
        </FieldShell>
    );
}
