import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Button } from '@/components/Button';
import { EnumerateShell } from '@/components/EnumerateShell';
import { SelectField, TextField } from '@/components/Field';
import type { EnumerateFrame } from '@/lib/enumerate';
import { cx } from '@/lib/cx';

type Part = 'about' | 'background' | 'phone' | 'agreements';

interface Choices {
    states: { id: number; name: string }[];
    lgas: { id: number; name: string; stateId: number }[];
    languages: string[];
    qualifications: Record<string, string>;
    experience: Record<string, string>;
    consents: Record<string, string>;
    fileKinds: Record<string, string>;
    governmentIds: string[];
}

interface FileRow {
    id: number;
    kind: string;
    label: string | null;
    mime: string;
    sizeBytes: number;
    uploadedAt: string;
}

interface Application {
    reference: string;
    status: 'draft' | 'submitted' | 'changes_requested' | 'approved' | 'rejected';
    editable: boolean;
    submittedAt: string | null;
    decidedAt: string | null;
    decisionNote: string | null;
    staffRef: string | null;
    email: string | null;
    about: {
        surname: string | null;
        first_name: string | null;
        middle_name: string | null;
        date_of_birth: string | null;
        sex: string | null;
        phone: string | null;
        whatsapp: string | null;
        residential_address: string | null;
        residence_state_id: number | null;
        residence_lga_id: number | null;
        origin_state_id: number | null;
        origin_lga_id: number | null;
        languages: string[];
    };
    background: {
        qualification: string | null;
        experience: string[];
        experience_years: number | null;
        guarantor: Record<string, string> | null;
        next_of_kin: Record<string, string> | null;
        ninLast4: string | null;
        bvnLast4: string | null;
    };
    device: Record<string, unknown> | null;
    consents: Record<string, string>;
    signedName: string | null;
    files: FileRow[];
    missing: Partial<Record<Part, string[]>>;
}

interface Props {
    frame: EnumerateFrame;
    application: Application | null;
    choices: Choices;
    adultBefore: string;
}

const PARTS: { key: Part; title: string; blurb: string }[] = [
    { key: 'about', title: 'About you', blurb: 'Your name, where you live, the languages you speak, and a clear photo of your face.' },
    { key: 'background', title: 'Background checks', blurb: 'Your NIN, an ID card, your education, a guarantor and someone we can call in an emergency.' },
    { key: 'phone', title: 'Your phone', blurb: 'A quick check that the phone you will work with can find its location accurately.' },
    { key: 'agreements', title: 'Agreements', blurb: 'Read and agree to how we keep your information and how agents work. Then sign with your name.' },
];

function useErrors(): Record<string, string | undefined> {
    return usePage().props.errors;
}

/**
 * Applying to become a field agent, inside the Enumerate dashboard.
 *
 * Four parts, each saved on its own so it can be finished over a few sittings
 * on a phone, then one button to send. Every field says why it is asked for,
 * in the words the onboarding brief used, because an applicant who knows why
 * we want a guarantor is an applicant who finds one.
 */
export default function AgentApplication({ frame, application, choices, adultBefore }: Props) {
    return (
        <EnumerateShell current="agent" frame={frame} title="Become an agent">
            <Head title="Become an agent" />
            {application === null ? <Start /> : <Applying application={application} choices={choices} adultBefore={adultBefore} />}
        </EnumerateShell>
    );
}

function Start() {
    const errors = useErrors();
    const needs = [
        'Your NIN (National Identification Number)',
        'A government ID: NIN slip, voter’s card, driver’s licence or passport',
        'A certificate for your highest qualification (SSCE or above)',
        'A guarantor: someone who knows you well and will vouch for you, with their ID',
        'An Android phone or iPhone with a working camera and location',
        'About 20 minutes. You can stop and come back: everything you save is kept',
    ];

    return (
        <div className="grid gap-5 lg:grid-cols-[1.2fr_0.8fr]">
            <section className="rounded-card border border-rule bg-raised px-6 py-6">
                <h2 className="font-display text-[1.6rem] font-extrabold text-ink">Work with GeoVerify as a field agent</h2>
                <p className="mt-2 max-w-[60ch] text-body text-muted">
                    Field agents visit businesses and places near where they live and record what they find, using the
                    GeoVerify app on their phone. We train you first, and a supervisor supports you every day.
                </p>
                <h3 className="mt-6 text-body font-extrabold text-ink">What you will need</h3>
                <ul className="mt-3 flex flex-col gap-2">
                    {needs.map((n) => (
                        <li key={n} className="flex gap-2.5 text-ui text-ink">
                            <span aria-hidden="true" className="mt-1.5 size-2 shrink-0 rounded-full bg-gold" />
                            {n}
                        </li>
                    ))}
                </ul>
                {errors.start !== undefined && <p className="mt-4 text-ui text-alert">{errors.start}</p>}
                <div className="mt-6">
                    <Button
                        variant="primary"
                        onClick={() => {
                            router.post('/enumerate/agent/start');
                        }}
                    >
                        Start my application
                    </Button>
                </div>
            </section>
            <WhatHappensNext current={0} />
        </div>
    );
}

function WhatHappensNext({ current }: { current: number }) {
    const steps = [
        ['Fill in the form', 'Four short parts. Save each one as you go.'],
        ['We check your details', 'Our team looks at your application, usually within a week. We email you either way.'],
        ['Training and a short test', 'We show you how the app works, how to visit a business politely, and how to stay safe.'],
        ['Start work', 'You get your own agent sign in and your first area to visit, close to where you live.'],
    ];

    return (
        <aside className="rounded-card border border-rule bg-sunken px-6 py-6">
            <h2 className="text-body font-extrabold text-ink">What happens next</h2>
            <ol className="mt-4 flex list-none flex-col gap-4 p-0">
                {steps.map(([title, body], i) => (
                    <li key={title} className="flex gap-3">
                        <span
                            className={cx(
                                'flex size-8 shrink-0 items-center justify-center rounded-full text-ui font-extrabold',
                                i < current ? 'bg-green text-inverse' : i === current ? 'bg-gold text-on-accent' : 'bg-raised text-muted',
                            )}
                        >
                            {i < current ? '✓' : i + 1}
                        </span>
                        <span>
                            <span className="block text-ui font-extrabold text-ink">{title}</span>
                            <span className="block text-table text-muted">{body}</span>
                        </span>
                    </li>
                ))}
            </ol>
        </aside>
    );
}

function StatusBanner({ application }: { application: Application }) {
    const map: Record<Application['status'], { tone: string; title: string; body: ReactNode }> = {
        draft: {
            tone: 'border-rule bg-raised',
            title: 'Your application is not sent yet',
            body: 'Fill in each part below and press Save. When every part has a green tick, press "Send my application".',
        },
        changes_requested: {
            tone: 'border-amber/40 bg-amber-soft',
            title: 'We need a few changes',
            body: (
                <>
                    Our team looked at your application and asked for this: <strong>{application.decisionNote}</strong>. Make the
                    change below, then send it again.
                </>
            ),
        },
        submitted: {
            tone: 'border-held/40 bg-held-soft',
            title: 'Application sent. Thank you!',
            body: 'Our team is checking your details. This usually takes about a week, and we will email you. You do not need to do anything now.',
        },
        approved: {
            tone: 'border-green/40 bg-green-soft',
            title: 'Congratulations, you are a GeoVerify agent',
            body: (
                <>
                    Your agent number is <strong>{application.staffRef}</strong>. We will invite you to training and give you your
                    first password there. After that, you sign in to the agent app at <strong>/login</strong> with {application.email}.
                </>
            ),
        },
        rejected: {
            tone: 'border-alert/40 bg-alert-soft',
            title: 'We cannot take your application forward',
            body: <>Reason: {application.decisionNote}. Thank you for your interest in GeoVerify.</>,
        },
    };
    const s = map[application.status];

    return (
        <section className={cx('rounded-card border px-6 py-5', s.tone)}>
            <p className="font-mono text-[0.75rem] text-muted">{application.reference}</p>
            <h2 className="mt-1 text-body font-extrabold text-ink">{s.title}</h2>
            <p className="mt-1 text-ui text-ink">{s.body}</p>
        </section>
    );
}

function Applying({ application, choices, adultBefore }: { application: Application; choices: Choices; adultBefore: string }) {
    const firstUnfinished = PARTS.find((p) => application.missing[p.key] !== undefined)?.key ?? null;
    const [open, setOpen] = useState<Part | null>(application.editable ? firstUnfinished : null);
    const errors = useErrors();
    const ready = Object.keys(application.missing).length === 0;
    const stage = application.status === 'approved' ? 4 : application.status === 'submitted' ? 1 : application.status === 'rejected' ? 1 : 0;

    return (
        <div className="grid gap-5 xl:grid-cols-[1fr_340px]">
            <div className="flex flex-col gap-4">
                <StatusBanner application={application} />

                {PARTS.map((part, i) => {
                    const missing = application.missing[part.key];
                    const done = missing === undefined;
                    const isOpen = open === part.key;

                    return (
                        <section key={part.key} className="overflow-hidden rounded-card border border-rule bg-raised">
                            <button
                                type="button"
                                aria-expanded={isOpen}
                                onClick={() => {
                                    setOpen(isOpen ? null : part.key);
                                }}
                                className="flex w-full items-center gap-4 px-5 py-4 text-left hover:bg-sunken/60"
                            >
                                <span
                                    className={cx(
                                        'flex size-9 shrink-0 items-center justify-center rounded-full text-ui font-extrabold',
                                        done ? 'bg-green text-inverse' : 'bg-sunken text-muted',
                                    )}
                                >
                                    {done ? '✓' : i + 1}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block text-body font-extrabold text-ink">{part.title}</span>
                                    <span className="block text-table text-muted">
                                        {done ? 'Done' : application.editable ? `Still needed: ${missing.join(', ')}` : part.blurb}
                                    </span>
                                </span>
                                <span aria-hidden="true" className="text-muted">
                                    {isOpen ? '−' : '+'}
                                </span>
                            </button>
                            {isOpen && (
                                <div className="border-t border-rule px-5 py-5">
                                    <p className="mb-4 text-ui text-muted">{part.blurb}</p>
                                    {part.key === 'about' && <AboutYou application={application} choices={choices} adultBefore={adultBefore} />}
                                    {part.key === 'background' && <Background application={application} choices={choices} />}
                                    {part.key === 'phone' && <PhoneCheck application={application} />}
                                    {part.key === 'agreements' && <Agreements application={application} choices={choices} />}
                                </div>
                            )}
                        </section>
                    );
                })}

                {application.editable && (
                    <section className="rounded-card border border-rule bg-raised px-5 py-5">
                        <h2 className="text-body font-extrabold text-ink">Send my application</h2>
                        <p className="mt-1 text-ui text-muted">
                            {ready
                                ? 'Everything is filled in. Once you send it, you cannot change it unless we ask you to.'
                                : 'The button works once every part above has a green tick.'}
                        </p>
                        {errors.submit !== undefined && <p className="mt-2 text-ui text-alert">{errors.submit}</p>}
                        <div className="mt-4">
                            <Button
                                variant="primary"
                                disabled={!ready}
                                onClick={() => {
                                    router.post('/enumerate/agent/submit', {}, { preserveScroll: true });
                                }}
                            >
                                Send my application
                            </Button>
                        </div>
                    </section>
                )}
            </div>
            <div>
                <WhatHappensNext current={stage} />
            </div>
        </div>
    );
}

function Locked({ application }: { application: Application }) {
    return application.editable ? null : <p className="mb-4 rounded-sm bg-sunken px-3 py-2 text-table text-muted">Sent. This part can no longer be changed.</p>;
}

function AboutYou({ application, choices, adultBefore }: { application: Application; choices: Choices; adultBefore: string }) {
    const a = application.about;
    const form = useForm({
        surname: a.surname ?? '',
        first_name: a.first_name ?? '',
        middle_name: a.middle_name ?? '',
        date_of_birth: a.date_of_birth ?? '',
        sex: a.sex ?? '',
        phone: a.phone ?? '',
        whatsapp: a.whatsapp ?? '',
        residential_address: a.residential_address ?? '',
        residence_state_id: a.residence_state_id === null ? '' : String(a.residence_state_id),
        residence_lga_id: a.residence_lga_id === null ? '' : String(a.residence_lga_id),
        origin_state_id: a.origin_state_id === null ? '' : String(a.origin_state_id),
        origin_lga_id: a.origin_lga_id === null ? '' : String(a.origin_lga_id),
        languages: a.languages,
    });
    const errors = useErrors();
    const lgasOf = (state: string) => choices.lgas.filter((l) => String(l.stateId) === state);
    const disabled = !application.editable;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({
                    ...d,
                    residence_state_id: Number(d.residence_state_id),
                    residence_lga_id: Number(d.residence_lga_id),
                    origin_state_id: d.origin_state_id === '' ? null : Number(d.origin_state_id),
                    origin_lga_id: d.origin_lga_id === '' ? null : Number(d.origin_lga_id),
                }));
                form.post('/enumerate/agent/about', { preserveScroll: true });
            }}
        >
            <Locked application={application} />
            <fieldset disabled={disabled} className="grid gap-4 sm:grid-cols-3">
                <TextField label="Surname" value={form.data.surname} onChange={(e) => { form.setData('surname', e.target.value); }} error={errors.surname} hint="As it is on your NIN" />
                <TextField label="First name" value={form.data.first_name} onChange={(e) => { form.setData('first_name', e.target.value); }} error={errors.first_name} />
                <TextField label="Middle name (if any)" value={form.data.middle_name} onChange={(e) => { form.setData('middle_name', e.target.value); }} error={errors.middle_name} />
                <TextField label="Date of birth" type="date" max={adultBefore} value={form.data.date_of_birth} onChange={(e) => { form.setData('date_of_birth', e.target.value); }} error={errors.date_of_birth} hint="You must be 18 or older" />
                <SelectField label="Sex" value={form.data.sex} onChange={(e) => { form.setData('sex', e.target.value); }} error={errors.sex} hint="As it is on your NIN">
                    <option value="">Choose</option>
                    <option value="female">Female</option>
                    <option value="male">Male</option>
                </SelectField>
                <div />
                <TextField label="Phone number" inputMode="tel" placeholder="0803 123 4567" value={form.data.phone} onChange={(e) => { form.setData('phone', e.target.value); }} error={errors.phone} hint="The number we call you on when you are working" />
                <TextField label="WhatsApp number (if different)" inputMode="tel" value={form.data.whatsapp} onChange={(e) => { form.setData('whatsapp', e.target.value); }} error={errors.whatsapp} hint="For your team group" />
                <div />
                <div className="sm:col-span-3">
                    <TextField label="Home address" value={form.data.residential_address} onChange={(e) => { form.setData('residential_address', e.target.value); }} error={errors.residential_address} hint="Street, area and town. We give you work close to home." />
                </div>
                <SelectField label="State you live in" value={form.data.residence_state_id} onChange={(e) => { form.setData((d) => ({ ...d, residence_state_id: e.target.value, residence_lga_id: '' })); }} error={errors.residence_state_id}>
                    <option value="">Choose a state</option>
                    {choices.states.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </SelectField>
                <SelectField label="Local government you live in" value={form.data.residence_lga_id} onChange={(e) => { form.setData('residence_lga_id', e.target.value); }} error={errors.residence_lga_id}>
                    <option value="">Choose a local government</option>
                    {lgasOf(form.data.residence_state_id).map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
                </SelectField>
                <div />
                <SelectField label="State of origin (optional)" value={form.data.origin_state_id} onChange={(e) => { form.setData((d) => ({ ...d, origin_state_id: e.target.value, origin_lga_id: '' })); }} hint="Some communities welcome agents from the area">
                    <option value="">Prefer not to say</option>
                    {choices.states.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </SelectField>
                <SelectField label="Local government of origin" value={form.data.origin_lga_id} onChange={(e) => { form.setData('origin_lga_id', e.target.value); }}>
                    <option value="">Prefer not to say</option>
                    {lgasOf(form.data.origin_state_id).map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
                </SelectField>
            </fieldset>

            <fieldset disabled={disabled} className="mt-5">
                <legend className="text-label font-bold tracking-[0.05em] text-muted uppercase">Languages you speak</legend>
                <p className="text-table text-faint">Choose every one you can hold a conversation in. We match agents to communities.</p>
                <div className="mt-2 flex flex-wrap gap-2">
                    {choices.languages.map((lang) => {
                        const on = form.data.languages.includes(lang);
                        return (
                            <button
                                key={lang}
                                type="button"
                                aria-pressed={on}
                                onClick={() => {
                                    form.setData('languages', on ? form.data.languages.filter((l) => l !== lang) : [...form.data.languages, lang]);
                                }}
                                className={cx('rounded-full border px-3.5 py-1.5 text-ui font-bold', on ? 'border-gold bg-gold-soft text-gold-dark' : 'border-rule-strong text-ink')}
                            >
                                {on ? '✓ ' : ''}
                                {lang}
                            </button>
                        );
                    })}
                </div>
                {errors.languages !== undefined && <p className="mt-1 text-ui text-alert">{errors.languages}</p>}
            </fieldset>

            {errors.about !== undefined && <p className="mt-4 text-ui text-alert">{errors.about}</p>}
            {application.editable && (
                <div className="mt-5">
                    <Button type="submit" variant="primary" busy={form.processing}>
                        Save this part
                    </Button>
                </div>
            )}

            <div className="mt-6 border-t border-rule pt-5">
                <Upload
                    application={application}
                    kind="passport_photo"
                    title="Passport photograph"
                    help="A clear photo of your face, looking at the camera, in good light. No cap or sunglasses. On a phone, this opens the front camera."
                    capture="user"
                    accept="image/*"
                />
            </div>
        </form>
    );
}

function Upload({ application, kind, title, help, capture, accept = 'image/*,application/pdf', labels }: { application: Application; kind: string; title: string; help: string; capture?: 'user' | 'environment'; accept?: string; labels?: string[] }) {
    const current = application.files.find((f) => f.kind === kind);
    const [label, setLabel] = useState(current?.label ?? labels?.[0] ?? '');
    const [busy, setBusy] = useState(false);
    const errors = useErrors();
    const input = useRef<HTMLInputElement>(null);

    const send = (file: File) => {
        setBusy(true);
        router.post(
            '/enumerate/agent/files',
            { kind, label: labels === undefined ? null : label, file },
            {
                forceFormData: true,
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                },
            },
        );
    };

    return (
        <div className="flex flex-col gap-2 rounded-sm border border-rule px-4 py-3 sm:flex-row sm:items-center">
            <div className="min-w-0 flex-1">
                <p className="text-ui font-extrabold text-ink">
                    {current !== undefined && <span className="mr-1.5 text-green">✓</span>}
                    {title}
                </p>
                <p className="text-table text-muted">{current !== undefined ? `Received${current.label !== null ? ` (${current.label})` : ''}. ${application.editable ? 'You can send a new one to replace it.' : ''}` : help}</p>
            </div>
            {application.editable && (
                <div className="flex flex-wrap items-center gap-2">
                    {labels !== undefined && (
                        <select value={label} onChange={(e) => { setLabel(e.target.value); }} aria-label={`Type of ${title}`} className="h-10 rounded-sm border border-rule-strong bg-raised px-2 text-table">
                            {labels.map((l) => <option key={l}>{l}</option>)}
                        </select>
                    )}
                    <input
                        ref={input}
                        type="file"
                        accept={accept}
                        {...(capture === undefined ? {} : { capture })}
                        className="sr-only"
                        onChange={(e) => {
                            const f = e.target.files?.[0];
                            if (f !== undefined) send(f);
                            e.target.value = '';
                        }}
                    />
                    <Button
                        busy={busy}
                        onClick={() => {
                            input.current?.click();
                        }}
                    >
                        {current === undefined ? (capture === 'user' ? 'Take photo' : 'Choose file') : 'Replace'}
                    </Button>
                </div>
            )}
            {errors.file !== undefined && <p className="text-ui text-alert sm:basis-full">{errors.file}</p>}
        </div>
    );
}

function Background({ application, choices }: { application: Application; choices: Choices }) {
    const b = application.background;
    const form = useForm({
        nin: '',
        bvn: '',
        qualification: b.qualification ?? '',
        experience: b.experience,
        experience_years: b.experience_years === null ? '' : String(b.experience_years),
        guarantor: { name: b.guarantor?.name ?? '', phone: b.guarantor?.phone ?? '', address: b.guarantor?.address ?? '', occupation: b.guarantor?.occupation ?? '' },
        next_of_kin: { name: b.next_of_kin?.name ?? '', relationship: b.next_of_kin?.relationship ?? '', phone: b.next_of_kin?.phone ?? '' },
    });
    const errors = useErrors();
    const g = (k: keyof typeof form.data.guarantor, v: string) => {
        form.setData('guarantor', { ...form.data.guarantor, [k]: v });
    };
    const n = (k: keyof typeof form.data.next_of_kin, v: string) => {
        form.setData('next_of_kin', { ...form.data.next_of_kin, [k]: v });
    };

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({ ...d, experience_years: d.experience_years === '' ? null : Number(d.experience_years) }));
                form.post('/enumerate/agent/background', {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.setData((d) => ({ ...d, nin: '', bvn: '' }));
                    },
                });
            }}
        >
            <Locked application={application} />
            <fieldset disabled={!application.editable} className="grid gap-4 sm:grid-cols-2">
                <TextField
                    label="NIN"
                    inputMode="numeric"
                    autoComplete="off"
                    placeholder={b.ninLast4 !== null ? `Saved: ends in ${b.ninLast4}` : '11 digits'}
                    value={form.data.nin}
                    onChange={(e) => { form.setData('nin', e.target.value); }}
                    error={errors.nin}
                    hint="We check it matches your name. We never keep the full number: only its last four digits."
                />
                <TextField
                    label="BVN (optional)"
                    inputMode="numeric"
                    autoComplete="off"
                    placeholder={b.bvnLast4 !== null ? `Saved: ends in ${b.bvnLast4}` : '11 digits'}
                    value={form.data.bvn}
                    onChange={(e) => { form.setData('bvn', e.target.value); }}
                    error={errors.bvn}
                    hint="Only needed if we pay you by bank transfer. Kept the same way as your NIN."
                />
                <SelectField label="Highest qualification" value={form.data.qualification} onChange={(e) => { form.setData('qualification', e.target.value); }} error={errors.qualification} hint="SSCE is the minimum">
                    <option value="">Choose</option>
                    {Object.entries(choices.qualifications).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </SelectField>
                <TextField label="Years of survey or census work (if any)" type="number" min={0} value={form.data.experience_years} onChange={(e) => { form.setData('experience_years', e.target.value); }} error={errors.experience_years} />
            </fieldset>

            <fieldset disabled={!application.editable} className="mt-4">
                <legend className="text-label font-bold tracking-[0.05em] text-muted uppercase">Have you done this kind of work before? (optional)</legend>
                <div className="mt-2 flex flex-wrap gap-2">
                    {Object.entries(choices.experience).map(([k, v]) => {
                        const on = form.data.experience.includes(k);
                        return (
                            <button key={k} type="button" aria-pressed={on} onClick={() => { form.setData('experience', on ? form.data.experience.filter((x) => x !== k) : [...form.data.experience, k]); }} className={cx('rounded-full border px-3.5 py-1.5 text-ui font-bold', on ? 'border-gold bg-gold-soft text-gold-dark' : 'border-rule-strong text-ink')}>
                                {on ? '✓ ' : ''}
                                {v}
                            </button>
                        );
                    })}
                </div>
            </fieldset>

            <h3 className="mt-6 text-body font-extrabold text-ink">Your guarantor</h3>
            <p className="text-table text-muted">Someone who knows you well, is not a relative, and will vouch for your honesty. They sign a form for us.</p>
            <fieldset disabled={!application.editable} className="mt-3 grid gap-4 sm:grid-cols-2">
                <TextField label="Full name" value={form.data.guarantor.name} onChange={(e) => { g('name', e.target.value); }} error={errors['guarantor.name']} />
                <TextField label="Phone number" inputMode="tel" value={form.data.guarantor.phone} onChange={(e) => { g('phone', e.target.value); }} error={errors['guarantor.phone']} />
                <TextField label="Home or office address" value={form.data.guarantor.address} onChange={(e) => { g('address', e.target.value); }} error={errors['guarantor.address']} />
                <TextField label="Occupation" placeholder="For example: teacher, civil servant, trader" value={form.data.guarantor.occupation} onChange={(e) => { g('occupation', e.target.value); }} error={errors['guarantor.occupation']} />
            </fieldset>

            <h3 className="mt-6 text-body font-extrabold text-ink">Next of kin</h3>
            <p className="text-table text-muted">Who we call if something happens to you while you are working.</p>
            <fieldset disabled={!application.editable} className="mt-3 grid gap-4 sm:grid-cols-3">
                <TextField label="Full name" value={form.data.next_of_kin.name} onChange={(e) => { n('name', e.target.value); }} error={errors['next_of_kin.name']} />
                <TextField label="Relationship to you" placeholder="For example: mother, brother, wife" value={form.data.next_of_kin.relationship} onChange={(e) => { n('relationship', e.target.value); }} error={errors['next_of_kin.relationship']} />
                <TextField label="Phone number" inputMode="tel" value={form.data.next_of_kin.phone} onChange={(e) => { n('phone', e.target.value); }} error={errors['next_of_kin.phone']} />
            </fieldset>

            {errors.background !== undefined && <p className="mt-4 text-ui text-alert">{errors.background}</p>}
            {application.editable && (
                <div className="mt-5">
                    <Button type="submit" variant="primary" busy={form.processing}>
                        Save this part
                    </Button>
                </div>
            )}

            <h3 className="mt-8 text-body font-extrabold text-ink">Documents</h3>
            <p className="text-table text-muted">A clear photo taken with your phone is fine. Make sure every corner is in the picture and the writing is readable.</p>
            <div className="mt-3 flex flex-col gap-2">
                <Upload application={application} kind="government_id" title="Your government ID" help="NIN slip, voter’s card, driver’s licence or international passport." labels={choices.governmentIds} capture="environment" />
                <Upload application={application} kind="qualification_certificate" title="Certificate for your highest qualification" help="SSCE, OND, HND, degree or similar." />
                <Upload application={application} kind="guarantor_id" title="Your guarantor’s ID" help="Any government ID of the person vouching for you." capture="environment" />
                <Upload
                    application={application}
                    kind="guarantor_form"
                    title="Signed guarantor form"
                    help="Print the form, ask your guarantor to fill it in and sign it, then send us a photo of it."
                />
                <a href="/become-an-agent/guarantor-form" target="_blank" rel="noreferrer" className="text-ui font-bold text-gold-dark underline underline-offset-2">
                    Open the guarantor form to print
                </a>
                <Upload application={application} kind="police_certificate" title="Police character certificate (optional)" help="Only some projects ask for this. Send it if you have one." />
            </div>
        </form>
    );
}

function PhoneCheck({ application }: { application: Application }) {
    const d = application.device ?? {};
    const [ownership, setOwnership] = useState(typeof d.ownership === 'string' ? d.ownership : '');
    const [powerBank, setPowerBank] = useState<'' | 'yes' | 'no'>(d.power_bank === true ? 'yes' : d.power_bank === false ? 'no' : '');
    const [best, setBest] = useState<number | null>(typeof d.gps_best_accuracy_m === 'number' ? d.gps_best_accuracy_m : null);
    const [samples, setSamples] = useState(0);
    const [running, setRunning] = useState(false);
    const [seconds, setSeconds] = useState(0);
    const [problem, setProblem] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const watch = useRef<number | null>(null);
    const errors = useErrors();

    const stop = () => {
        if (watch.current !== null) navigator.geolocation.clearWatch(watch.current);
        watch.current = null;
        setRunning(false);
    };

    useEffect(() => {
        if (!running) return;
        const t = window.setInterval(() => {
            setSeconds((s) => {
                if (s + 1 >= 120) stop();
                return s + 1;
            });
        }, 1000);
        return () => {
            window.clearInterval(t);
        };
    }, [running]);

    useEffect(() => stop, []);

    const start = () => {
        if (!('geolocation' in navigator)) {
            setProblem('This browser cannot read your location. Try Chrome on your phone.');
            return;
        }
        setProblem(null);
        setSamples(0);
        setSeconds(0);
        setRunning(true);
        watch.current = navigator.geolocation.watchPosition(
            (pos) => {
                setSamples((n) => n + 1);
                setBest((b) => (b === null || pos.coords.accuracy < b ? Math.round(pos.coords.accuracy * 10) / 10 : b));
            },
            (err) => {
                setProblem(err.code === err.PERMISSION_DENIED ? 'Your phone said no to sharing location. Allow location for this site in your browser settings, then try again.' : 'We could not get a location. Go outside, away from tall buildings, and try again.');
                stop();
            },
            { enableHighAccuracy: true, maximumAge: 0, timeout: 30000 },
        );
    };

    const save = async () => {
        setBusy(true);
        let storageFree: number | null = null;
        try {
            const est = await navigator.storage.estimate();
            if (est.quota !== undefined) storageFree = Math.round((est.quota - (est.usage ?? 0)) / (1024 * 1024));
        } catch {
            storageFree = null;
        }
        router.post(
            '/enumerate/agent/device',
            {
                ownership,
                power_bank: powerBank === 'yes',
                user_agent: navigator.userAgent.slice(0, 400),
                platform: navigator.platform.slice(0, 80),
                storage_free_mb: storageFree,
                gps_best_accuracy_m: best,
                gps_samples: samples,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                },
            },
        );
    };

    const verdict = best === null ? null : best <= 10 ? { tone: 'text-green', text: 'Good. Your phone finds its location accurately.' } : best <= 30 ? { tone: 'text-amber-ink', text: 'Fair. Try again outside, away from buildings, for a better result.' } : { tone: 'text-alert', text: 'Weak. Go outside under open sky and run the test again.' };

    return (
        <div>
            <Locked application={application} />
            <p className="rounded-sm bg-gold-soft px-4 py-3 text-ui text-ink">
                Do this part <strong>on the phone you will work with</strong>, standing <strong>outside</strong>. It reads your location for up to two minutes to see how accurate the phone is.
            </p>
            <fieldset disabled={!application.editable} className="mt-4 grid gap-4 sm:grid-cols-2">
                <SelectField label="Whose phone is it?" value={ownership} onChange={(e) => { setOwnership(e.target.value); }} error={errors.ownership}>
                    <option value="">Choose</option>
                    <option value="personal">My own phone</option>
                    <option value="company">A phone GeoVerify gave me</option>
                </SelectField>
                <SelectField label="Do you have a power bank?" value={powerBank} onChange={(e) => { setPowerBank(e.target.value as '' | 'yes' | 'no'); }} error={errors.power_bank} hint="Days in the field are long. Needed for rural projects.">
                    <option value="">Choose</option>
                    <option value="yes">Yes</option>
                    <option value="no">No</option>
                </SelectField>
            </fieldset>

            <div className="mt-5 rounded-sm border border-rule px-4 py-4">
                <p className="text-ui font-extrabold text-ink">Location test</p>
                {best !== null && (
                    <p className="mt-1 text-body text-ink">
                        Best accuracy: <strong>{best} metres</strong> {running && <span className="text-table text-muted">({String(samples)} readings, {String(120 - seconds)}s left)</span>}
                    </p>
                )}
                {verdict !== null && !running && <p className={cx('mt-1 text-ui font-bold', verdict.tone)}>{verdict.text}</p>}
                {problem !== null && <p className="mt-1 text-ui text-alert">{problem}</p>}
                {errors.gps_best_accuracy_m !== undefined && <p className="mt-1 text-ui text-alert">{errors.gps_best_accuracy_m}</p>}
                {application.editable && (
                    <div className="mt-3 flex flex-wrap gap-2">
                        {running ? (
                            <Button onClick={stop}>Stop test</Button>
                        ) : (
                            <Button onClick={start}>{best === null ? 'Start location test' : 'Run the test again'}</Button>
                        )}
                    </div>
                )}
            </div>

            {errors.device !== undefined && <p className="mt-4 text-ui text-alert">{errors.device}</p>}
            {application.editable && (
                <div className="mt-5">
                    <Button variant="primary" busy={busy} disabled={running || best === null || ownership === '' || powerBank === ''} onClick={() => { void save(); }}>
                        Save this part
                    </Button>
                </div>
            )}
        </div>
    );
}

function Agreements({ application, choices }: { application: Application; choices: Choices }) {
    const given = Object.keys(application.consents);
    const [agreed, setAgreed] = useState<string[]>(given);
    const [name, setName] = useState(application.signedName ?? '');
    const [busy, setBusy] = useState(false);
    const errors = useErrors();
    const all = Object.keys(choices.consents);

    return (
        <div>
            <Locked application={application} />
            <fieldset disabled={!application.editable} className="flex flex-col gap-3">
                {Object.entries(choices.consents).map(([key, text]) => (
                    <label key={key} className="flex cursor-pointer gap-3 rounded-sm border border-rule px-4 py-3 text-ui text-ink has-[:checked]:border-gold has-[:checked]:bg-gold-soft/50">
                        <input
                            type="checkbox"
                            checked={agreed.includes(key)}
                            onChange={(e) => {
                                setAgreed(e.target.checked ? [...agreed, key] : agreed.filter((k) => k !== key));
                            }}
                            className="mt-1 size-4 shrink-0 accent-gold"
                        />
                        <span>{text}</span>
                    </label>
                ))}
                <TextField label="Sign by typing your full name" value={name} onChange={(e) => { setName(e.target.value); }} error={errors.signed_name} hint="This counts as your signature on the agreements above." />
            </fieldset>
            {errors.agreements !== undefined && <p className="mt-3 text-ui text-alert">{errors.agreements}</p>}
            {application.editable && (
                <div className="mt-5">
                    <Button
                        variant="primary"
                        busy={busy}
                        disabled={agreed.length < all.length || name.trim().length < 3}
                        onClick={() => {
                            setBusy(true);
                            router.post('/enumerate/agent/agree', { agreed, signed_name: name }, { preserveScroll: true, onFinish: () => { setBusy(false); } });
                        }}
                    >
                        I agree, save
                    </Button>
                </div>
            )}
        </div>
    );
}
