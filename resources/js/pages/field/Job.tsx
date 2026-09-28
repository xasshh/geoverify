import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/Button';
import { GpsCard } from '@/components/CaptureChrome';
import { FieldShell } from '@/components/FieldShell';
import { compressPhotograph } from '@/lib/capture';
import { cx } from '@/lib/cx';
import type { OfficerDay } from '@/lib/fieldDay';
import { recordJobAction, retryJob, useJobActions } from '@/lib/offline/jobs';

interface Job {
    id: number;
    kind: 'inspection' | 'site_visit';
    label: string;
    status: string;
    orderRef: string | null;
    business: string | null;
    place: string;
    plusCode: string | null;
    requestedFor: string | null;
    visitMode: 'with_me' | 'for_me' | null;
    buyer: { name: string; phone: string } | null;
    items: { name: string; unit: string | null; quantity: number }[];
    checklist: { key: string; label: string }[];
    arrivedAt: string | null;
    photos: number;
    submittedAt: string | null;
}

type Fix = { latitude: number; longitude: number; accuracy_m: number | null };

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
 * An inspection or site visit, for the agent. Arrive, check the goods against
 * the order, photograph them, file the report. Every step is written to the
 * device first and sent in order when there is signal.
 */
export default function Job({ day, job }: { day: OfficerDay; job: Job }) {
    const position = usePosition();
    const actions = useJobActions(job.id);
    const [answers, setAnswers] = useState<Record<string, { passed: boolean | null; detail: string }>>(() =>
        Object.fromEntries(job.checklist.map((c) => [c.key, { passed: null, detail: '' }])),
    );
    const [notes, setNotes] = useState('');
    const [busy, setBusy] = useState(false);
    const input = useRef<HTMLInputElement | null>(null);

    const arrived = job.arrivedAt !== null || actions.some((a) => a.type === 'arrive');
    const photosTaken = job.photos + actions.filter((a) => a.type === 'photo').length;
    const reported = job.submittedAt !== null || actions.some((a) => a.type === 'report');
    const waiting = actions.filter((a) => a.state === 'queued').length;
    const refused = actions.find((a) => a.state === 'failed');
    const complete = job.checklist.every((c) => answers[c.key]?.passed !== null);

    const arrive = () => {
        if (position === null) {
            return;
        }

        void recordJobAction(job.id, 'arrive', { longitude: position.longitude, latitude: position.latitude, accuracy_m: position.accuracy_m });
    };

    const photograph = async (file: File) => {
        setBusy(true);

        try {
            const blob = await compressPhotograph(file);
            await recordJobAction(
                job.id,
                'photo',
                position === null ? {} : { device_longitude: position.longitude, device_latitude: position.latitude },
                blob,
            );
        } finally {
            setBusy(false);
        }
    };

    const submit = () => {
        void recordJobAction(job.id, 'report', {
            answers: Object.fromEntries(job.checklist.map((c) => [c.key, { passed: answers[c.key]?.passed === true, detail: answers[c.key]?.detail ?? '' }])),
            notes,
        });
    };

    return (
        <FieldShell day={day} current="today" title={job.label}>
            <div className="mx-auto flex max-w-3xl flex-col gap-5">
                <section className="rounded-card border border-rule bg-raised px-5 py-5">
                    <p className="text-label font-extrabold tracking-[0.05em] text-gold-dark uppercase">
                        {job.label} · order {job.orderRef}
                    </p>
                    <h2 className="mt-1 font-display text-display-s text-ink">{job.business}</h2>
                    <p className="text-ui text-muted">
                        {job.place}
                        {job.plusCode !== null && <span className="numeric-mono"> · {job.plusCode}</span>}
                    </p>
                    {job.requestedFor !== null && (
                        <p className="mt-2 text-ui text-ink">
                            <span className="font-bold">{new Date(job.requestedFor).toLocaleString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}</span>
                            {job.visitMode === 'with_me' ? ' · the buyer meets you there' : ' · you go for the buyer'}
                        </p>
                    )}
                    {job.buyer !== null && (
                        <p className="mt-1 text-ui text-ink">
                            Buyer: {job.buyer.name} ·{' '}
                            <a href={`tel:${job.buyer.phone}`} className="font-bold text-gold">
                                {job.buyer.phone}
                            </a>
                        </p>
                    )}
                </section>

                {reported ? (
                    <section className="rounded-card border border-green/30 bg-green-soft px-5 py-5">
                        <h2 className="font-display text-display-s text-ink">Report filed</h2>
                        <p className="mt-1 max-w-none text-ui text-ink">
                            {waiting > 0 ? `Kept on this device, ${String(waiting)} ${waiting === 1 ? 'item' : 'items'} to send when there is signal.` : 'Sent. The buyer approves it before the goods are dispatched.'}
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

                        <section className="rounded-card border border-rule bg-raised px-5 py-5">
                            <h2 className="text-body font-extrabold text-ink">What was ordered</h2>
                            <ul className="mt-2 flex list-none flex-col divide-y divide-rule p-0">
                                {job.items.map((item) => (
                                    <li key={item.name} className="flex justify-between gap-3 py-2 text-ui">
                                        <span className="text-ink">
                                            {item.name}
                                            {item.unit !== null && <span className="text-muted"> · {item.unit}</span>}
                                        </span>
                                        <span className="font-extrabold text-ink">× {item.quantity}</span>
                                    </li>
                                ))}
                            </ul>
                        </section>

                        <section className={cx('rounded-card border border-rule bg-raised px-5 py-5', !arrived && 'opacity-60')}>
                            <h2 className="text-body font-extrabold text-ink">Checks</h2>
                            <ul className="mt-3 flex list-none flex-col gap-4 p-0">
                                {job.checklist.map((check) => {
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
                                                placeholder="What you saw, for example 50.2 kg, 49.8 kg"
                                                aria-label={`${check.label}: detail`}
                                                className="mt-2 h-11 w-full rounded-sm border border-rule-strong bg-raised px-3 text-ui text-ink"
                                            />
                                        </li>
                                    );
                                })}
                            </ul>
                        </section>

                        <section className={cx('rounded-card border border-rule bg-raised px-5 py-5', !arrived && 'opacity-60')}>
                            <p className="flex items-center justify-between text-body font-extrabold text-ink">
                                Photographs
                                <span className={cx('text-table', photosTaken > 0 ? 'text-green' : 'text-gold-dark')}>{photosTaken} taken</span>
                            </p>
                            <p className="mt-1 max-w-none text-table text-muted">
                                The goods, the scale, the seals and the packed order. The buyer and the business see these, nobody else.
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
                                        void photograph(file);
                                    }

                                    e.target.value = '';
                                }}
                            />
                            <div className="mt-3">
                                <Button variant="secondary" size="field" busy={busy} disabled={!arrived} onClick={() => input.current?.click()}>
                                    + Take a photograph
                                </Button>
                            </div>
                        </section>

                        <section className={cx('rounded-card border border-rule bg-raised px-5 py-5', !arrived && 'opacity-60')}>
                            <label htmlFor="notes" className="text-body font-extrabold text-ink">
                                Notes for the buyer
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
                                <button type="button" onClick={() => void retryJob(job.id)} className="mt-1 font-bold underline">
                                    Try again
                                </button>
                            </div>
                        )}

                        <Button variant="primary" size="field-primary" fullWidth disabled={!arrived || !complete || photosTaken === 0} onClick={submit}>
                            File the report
                        </Button>
                        <p className="max-w-none text-center text-table text-muted">Kept on this device and sent when there is signal.</p>
                    </>
                )}
            </div>
        </FieldShell>
    );
}
