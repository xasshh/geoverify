import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useRef } from 'react';
import { Button } from '@/components/Button';
import { SelectField, TextField } from '@/components/Field';
import { PortalShell } from '@/components/PortalShell';
import { buttonClass } from '@/lib/button';
import { cx } from '@/lib/cx';

interface Props {
    business: { id: number; name: string };
    seekingOptions: { value: string; label: string }[];
    opportunity: {
        id: number;
        status: 'draft' | 'published';
        seeking: string;
        ticketSizeNaira: number | null;
        useOfFunds: string | null;
        summary: string | null;
        operatingSince: number | null;
        staffOnSite: string | null;
        premises: string | null;
        publishedAt: string | null;
        interested: number;
    } | null;
    documents: { id: number; title: string; description: string | null; bytes: number; addedAt: string | null }[];
    requests: {
        id: number;
        organisation: string | null;
        kind: string | null;
        verified: boolean;
        status: 'requested' | 'granted' | 'declined' | 'revoked';
        message: string | null;
        requestedAt: string | null;
    }[];
}

function size(bytes: number): string {
    return bytes > 1_000_000 ? `${(bytes / 1_000_000).toFixed(1)} MB` : `${String(Math.max(1, Math.round(bytes / 1000)))} KB`;
}

/**
 * What this business tells investors, and who may read its data room.
 *
 * Nothing here is visible to anybody until the owner publishes. Publishing
 * shows the opportunity to verified investors only; the data room opens per
 * organisation, on the owner's say.
 */
export default function Investors({ business, seekingOptions, opportunity, documents, requests }: Props) {
    const accountName = usePage().props.auth.portal?.name ?? business.name;
    const base = `/portal/businesses/${String(business.id)}/investors`;
    const published = opportunity?.status === 'published';

    const form = useForm({
        seeking: opportunity?.seeking ?? seekingOptions[0]?.value ?? 'expansion_equity',
        ticket_size_naira: opportunity?.ticketSizeNaira?.toString() ?? '',
        use_of_funds: opportunity?.useOfFunds ?? '',
        summary: opportunity?.summary ?? '',
        operating_since: opportunity?.operatingSince?.toString() ?? '',
        staff_on_site: opportunity?.staffOnSite ?? '',
        premises: opportunity?.premises ?? '',
        publish: false,
    });

    const upload = useForm<{ title: string; description: string; document: File | null }>({
        title: '',
        description: '',
        document: null,
    });
    const fileInput = useRef<HTMLInputElement>(null);

    const submit = (publish: boolean) => {
        form.transform((data) => ({
            ...data,
            publish,
            ticket_size_naira: data.ticket_size_naira === '' ? null : Number(data.ticket_size_naira),
            operating_since: data.operating_since === '' ? null : Number(data.operating_since),
        }));
        form.post(base, { preserveScroll: true });
    };

    const text = (key: 'use_of_funds' | 'summary' | 'staff_on_site' | 'premises' | 'ticket_size_naira' | 'operating_since', label: string, hint?: string, type = 'text') => (
        <TextField
            label={label}
            type={type}
            value={form.data[key]}
            onChange={(e) => {
                form.setData(key, e.target.value);
            }}
            {...(hint === undefined ? {} : { hint })}
            {...(form.errors[key] === undefined ? {} : { error: form.errors[key] })}
        />
    );

    const pending = requests.filter((r) => r.status === 'requested');

    return (
        <PortalShell
            accountName={accountName}
            width="page"
            kicker={business.name}
            title="Investors"
            subtitle={
                opportunity !== null && published
                    ? `Published. ${String(opportunity.interested)} ${opportunity.interested === 1 ? 'investor has' : 'investors have'} expressed interest.`
                    : 'Not visible to investors until you publish.'
            }
            actions={
                published ? (
                    <button
                        type="button"
                        onClick={() => {
                            router.post(`${base}/withdraw`, {}, { preserveScroll: true });
                        }}
                        className={buttonClass('destructive', 'field')}
                    >
                        Withdraw from investors
                    </button>
                ) : undefined
            }
        >
            <Head title="Investors" />

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px] xl:items-start">
                <section className="rounded-card border border-rule bg-raised p-6 shadow-card sm:p-7">
                    <h2 className="font-display text-display-s text-ink">What you are seeking</h2>
                    <p className="mt-1 text-ui text-muted">
                        Shown to verified investors beside your verification score. Your phone,
                        your exact location and any officer&apos;s photographs are never shown.
                    </p>

                    <div className="mt-6 flex flex-col gap-5">
                        <SelectField
                            label="Seeking"
                            value={form.data.seeking}
                            onChange={(e) => {
                                form.setData('seeking', e.target.value);
                            }}
                        >
                            {seekingOptions.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </SelectField>
                        <div className="grid gap-5 sm:grid-cols-2">
                            {text('ticket_size_naira', 'Ticket size in naira', 'Optional', 'number')}
                            {text('operating_since', 'Operating since', 'The year you started', 'number')}
                        </div>
                        {text('use_of_funds', 'Use of funds', 'For example: a second milling line')}
                        <div className="grid gap-5 sm:grid-cols-2">
                            {text('staff_on_site', 'Staff on site', 'For example: 60 to 80')}
                            {text('premises', 'Premises', 'For example: 2.4 ha, owner occupied')}
                        </div>
                        <label className="flex flex-col gap-1.5">
                            <span className="text-label font-bold tracking-[0.05em] text-muted uppercase">About the business</span>
                            <textarea
                                rows={5}
                                value={form.data.summary}
                                onChange={(e) => {
                                    form.setData('summary', e.target.value);
                                }}
                                className="rounded-sm border border-rule-strong bg-raised p-3.5 text-ui text-ink focus:border-gold"
                            />
                        </label>
                        <div className="flex flex-wrap gap-3">
                            {!published && (
                                <Button
                                    variant="primary"
                                    size="field"
                                    busy={form.processing}
                                    onClick={() => {
                                        submit(true);
                                    }}
                                >
                                    Publish to investors
                                </Button>
                            )}
                            <Button
                                variant={published ? 'primary' : 'secondary'}
                                size="field"
                                busy={form.processing}
                                onClick={() => {
                                    submit(false);
                                }}
                            >
                                {published ? 'Save changes' : 'Save draft'}
                            </Button>
                        </div>
                    </div>
                </section>

                <div className="flex flex-col gap-6">
                    <section className="rounded-card border border-rule bg-raised p-6 shadow-card">
                        <div className="flex items-baseline justify-between gap-3">
                            <h2 className="font-display text-display-s text-ink">Access requests</h2>
                            {pending.length > 0 && (
                                <span className="rounded-full bg-gold px-2.5 py-0.5 text-label font-extrabold tracking-normal text-on-accent">
                                    {pending.length}
                                </span>
                            )}
                        </div>
                        {requests.length === 0 ? (
                            <p className="mt-3 text-ui text-muted">
                                When a verified investor asks to see your data room, you decide here.
                            </p>
                        ) : (
                            <ul className="mt-3 flex list-none flex-col">
                                {requests.map((r) => (
                                    <li key={r.id} className="border-t border-rule py-4 first:border-t-0">
                                        <p className="text-ui font-extrabold text-ink">{r.organisation}</p>
                                        <p className="text-table text-muted">
                                            {r.kind} · {r.verified ? 'Verified investor' : 'Not yet verified'}
                                        </p>
                                        {r.message !== null && <p className="mt-2 text-ui text-muted">{r.message}</p>}
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            {r.status === 'requested' && (
                                                <>
                                                    <Decide base={base} id={r.id} decision="granted" label="Grant access" variant="primary" />
                                                    <Decide base={base} id={r.id} decision="declined" label="Decline" variant="secondary" />
                                                </>
                                            )}
                                            {r.status === 'granted' && (
                                                <>
                                                    <span className="self-center rounded-full bg-gold-soft px-3 py-1 text-table font-bold text-gold-dark">Has access</span>
                                                    <Decide base={base} id={r.id} decision="revoked" label="Revoke" variant="destructive" />
                                                </>
                                            )}
                                            {(r.status === 'declined' || r.status === 'revoked') && (
                                                <span className="rounded-full bg-graphite-soft px-3 py-1 text-table font-bold text-muted">
                                                    {r.status === 'declined' ? 'Declined' : 'Revoked'}
                                                </span>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section className="rounded-card border border-rule bg-raised p-6 shadow-card">
                        <h2 className="font-display text-display-s text-ink">Data room</h2>
                        <p className="mt-1 text-ui text-muted">
                            Only organisations you grant can open these. Audited accounts, management
                            accounts and title documents are what investors ask for first.
                        </p>
                        <ul className="mt-3 flex list-none flex-col">
                            {documents.map((d) => (
                                <li key={d.id} className="flex items-center gap-3 border-t border-rule py-3 first:border-t-0">
                                    <span className="flex min-w-0 flex-1 flex-col">
                                        <span className="truncate text-ui font-bold text-ink">{d.title}</span>
                                        <span className="truncate text-table text-muted">
                                            {[d.description, size(d.bytes)].filter(Boolean).join(' · ')}
                                        </span>
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            router.post(`${base}/documents/${String(d.id)}/withdraw`, {}, { preserveScroll: true });
                                        }}
                                        className="text-table font-bold text-muted hover:text-alert-ink"
                                    >
                                        Remove
                                    </button>
                                </li>
                            ))}
                        </ul>

                        {opportunity === null ? (
                            <p className="mt-3 rounded-sm bg-sunken px-4 py-3 text-ui text-muted">
                                Save what you are seeking first, then add documents.
                            </p>
                        ) : (
                            <form
                                className="mt-4 flex flex-col gap-3 border-t border-rule pt-4"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    upload.post(`${base}/documents`, {
                                        preserveScroll: true,
                                        forceFormData: true,
                                        onSuccess: () => {
                                            upload.reset();
                                            if (fileInput.current !== null) {
                                                fileInput.current.value = '';
                                            }
                                        },
                                    });
                                }}
                            >
                                <TextField
                                    label="Document title"
                                    value={upload.data.title}
                                    onChange={(e) => {
                                        upload.setData('title', e.target.value);
                                    }}
                                    {...(upload.errors.title === undefined ? {} : { error: upload.errors.title })}
                                />
                                <TextField
                                    label="Short description"
                                    value={upload.data.description}
                                    hint="Optional. For example: shared by the business, 2024 to 2025"
                                    onChange={(e) => {
                                        upload.setData('description', e.target.value);
                                    }}
                                />
                                <input
                                    ref={fileInput}
                                    type="file"
                                    accept=".pdf,.xlsx,.xls,.csv,.docx,.doc,.jpg,.jpeg,.png"
                                    onChange={(e) => {
                                        upload.setData('document', e.target.files?.[0] ?? null);
                                    }}
                                    className={cx(
                                        'text-ui text-muted file:mr-3 file:min-h-touch file:rounded-sm file:border file:border-rule-strong file:bg-raised file:px-4 file:font-bold file:text-ink',
                                    )}
                                />
                                {upload.errors.document !== undefined && (
                                    <p className="text-ui text-alert-ink">{upload.errors.document}</p>
                                )}
                                <div>
                                    <Button type="submit" variant="soft" size="field" busy={upload.processing}>
                                        Add to data room
                                    </Button>
                                </div>
                            </form>
                        )}
                    </section>
                </div>
            </div>
        </PortalShell>
    );
}

function Decide({
    base,
    id,
    decision,
    label,
    variant,
}: {
    base: string;
    id: number;
    decision: 'granted' | 'declined' | 'revoked';
    label: string;
    variant: 'primary' | 'secondary' | 'destructive';
}) {
    return (
        <button
            type="button"
            onClick={() => {
                router.post(`${base}/requests/${String(id)}`, { decision }, { preserveScroll: true });
            }}
            className={buttonClass(variant, 'field-compact')}
        >
            {label}
        </button>
    );
}
