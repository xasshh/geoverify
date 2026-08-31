import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { ConsoleShell } from '@/components/ConsoleShell';
import { SelectField, TextField } from '@/components/Field';
import { StatusPill } from '@/components/StatusPill';
import { Progress } from '@/components/CampaignWidgets';
import { campaignTone, on, type CampaignDossier } from '@/lib/campaign';
import { cx } from '@/lib/cx';

interface Props {
    campaign: CampaignDossier;
    commercial: {
        contractValue: string | null;
        currency: string;
        paymentStatus: string;
        paidAt: string | null;
        internalNotes: string | null;
    } | null;
    allowedTransitions: Array<{ value: string; label: string }>;
    unassignedAreas: Array<{ id: number; name: string }>;
    officers: Array<{ id: number; name: string; staffRef: string | null }>;
    vocabulary: {
        fieldTypes: Array<{ value: string; label: string; takesOptions: boolean }>;
        categories: Array<{ value: string; label: string }>;
        engagement: Array<{ value: string; label: string }>;
        payment: Array<{ value: string; label: string }>;
    };
}

/**
 * The seven sections a campaign is built from.
 *
 * Tabs in the brief's order: definition, scope, schema, stakeholders,
 * deployment, commercials, review. Not a wizard, deliberately. A wizard is right
 * the first time through and wrong every time after, and this screen is opened
 * far more often to change one stakeholder than to write a campaign from
 * nothing.
 *
 * Commercials render only when the server sent them. The prop is null for
 * anybody who cannot see them, so the tab has nothing to hide rather than
 * hidden content.
 */
const SECTIONS = [
    'Definition',
    'Scope & areas',
    'Data schema',
    'Stakeholders',
    'Deployment',
    'Commercials',
    'Review',
] as const;

type Section = (typeof SECTIONS)[number];

function Panel({ children }: { children: React.ReactNode }) {
    return <div className="mt-6 rounded-sm border border-rule-strong p-5">{children}</div>;
}

export default function Campaign({
    campaign,
    commercial,
    allowedTransitions,
    unassignedAreas,
    officers,
    vocabulary,
}: Props) {
    const flash = usePage().props.flash.status;
    const [section, setSection] = useState<Section>('Definition');
    const [confirming, setConfirming] = useState<string | null>(null);

    const definition = useForm({
        name: campaign.name,
        subject_type: campaign.subjectType,
        objective: campaign.objective ?? '',
        about: campaign.about ?? '',
        starts_on: campaign.timeline.startsOn ?? '',
        ends_on: campaign.timeline.endsOn ?? '',
        target_record_count: campaign.collection.target?.toString() ?? '',
        materially_revised: false,
    });

    const move = useForm({ status: '', note: '', override_start_date: false });
    const money = useForm({
        contract_value: commercial?.contractValue ?? '',
        currency: commercial?.currency ?? 'NGN',
        payment_status: commercial?.paymentStatus ?? 'unpaid',
        paid_at: commercial?.paidAt ?? '',
        internal_notes: commercial?.internalNotes ?? '',
    });
    const area = useForm({ coverage_area_id: '', target_record_count: '' });
    const field = useForm({
        label: '',
        key: '',
        type: 'text',
        options: '',
        is_required: false,
        sort_order: campaign.schema.fieldCount,
        help_text: '',
    });
    const stakeholder = useForm({
        name: '',
        category: 'government_agency',
        organisation: '',
        role_title: '',
        contact_person: '',
        phone: '',
        email: '',
        engagement_status: 'identified',
        notes: '',
        visible_to_client: true,
    });
    const deploy = useForm<{ user_ids: number[]; coverage_area_id: string }>({
        user_ids: [],
        coverage_area_id: '',
    });

    const base = `/admin/campaigns/${String(campaign.id)}`;

    return (
        <ConsoleShell current="campaigns">
            <Head title={campaign.name} />

            <div className="mx-auto max-w-[1200px] px-6 pb-24">
                <header className="mt-8 flex flex-wrap items-start justify-between gap-4 border-b-[1.5px] border-ink pb-3">
                    <div>
                        <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
                            <Link href="/admin/campaigns" className="underline underline-offset-2">
                                Campaigns
                            </Link>
                            <span className="numeric-mono ml-2 text-faint">{campaign.code}</span>
                        </p>
                        <h1 className="font-display text-display-m text-ink">{campaign.name}</h1>
                        <p className="mt-0.5 text-ui text-muted">
                            {campaign.client.name} &middot; {campaign.subjectType}
                        </p>
                    </div>
                    <StatusPill
                        tone={campaignTone(campaign.status)}
                        label={campaign.statusLabel}
                        emphasis="filled"
                    />
                </header>

                {flash !== null && (
                    <p className="mt-4 border-l-2 border-green bg-raised px-4 py-2.5 text-ui text-ink">
                        {flash}
                    </p>
                )}

                {/* Status transitions as named acts, each confirmed. A dropdown
                    would let somebody activate a draft and skip the approval
                    that puts officers on the road. */}
                <div className="mt-5 flex flex-wrap items-center gap-2">
                    {allowedTransitions.map((transition) => (
                        <Button
                            key={transition.value}
                            variant={transition.value === 'active' ? 'primary' : 'secondary'}
                            onClick={() => {
                                setConfirming(transition.value);
                                move.setData('status', transition.value);
                            }}
                        >
                            {transition.label}
                        </Button>
                    ))}
                    {allowedTransitions.length === 0 && (
                        <p className="text-ui text-faint">
                            This campaign is settled. Nothing moves from here.
                        </p>
                    )}
                </div>

                {confirming !== null && (
                    <div className="mt-4 flex flex-col gap-3 rounded-sm border border-rule-strong bg-raised p-4">
                        <p className="text-ui text-ink">
                            Move this campaign to{' '}
                            <strong>
                                {allowedTransitions.find((t) => t.value === confirming)?.label}
                            </strong>
                            ? Every move is written to the log.
                        </p>

                        <TextField
                            label="Note (optional)"
                            value={move.data.note}
                            onChange={(e) => {
                                move.setData('note', e.target.value);
                            }}
                        />

                        {confirming === 'active' && (
                            <label className="flex items-center gap-3 text-ui text-ink">
                                <input
                                    type="checkbox"
                                    checked={move.data.override_start_date}
                                    onChange={(e) => {
                                        move.setData('override_start_date', e.target.checked);
                                    }}
                                    className="size-5 rounded-[2px] border-rule-strong accent-gold"
                                />
                                Activate before the contracted start date
                            </label>
                        )}

                        {move.errors.status !== undefined && (
                            <p className="text-label text-alert">{move.errors.status}</p>
                        )}

                        <div className="flex gap-3">
                            <Button
                                onClick={() => {
                                    move.post(`${base}/transition`, {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setConfirming(null);
                                        },
                                    });
                                }}
                                busy={move.processing}
                            >
                                Confirm
                            </Button>
                            <button
                                type="button"
                                onClick={() => {
                                    setConfirming(null);
                                }}
                                className="text-ui text-muted underline underline-offset-2"
                            >
                                Cancel
                            </button>
                        </div>
                    </div>
                )}

                <nav className="mt-8 flex flex-wrap gap-1 border-b border-rule" aria-label="Sections">
                    {SECTIONS.filter((s) => s !== 'Commercials' || commercial !== null).map((s) => (
                        <button
                            key={s}
                            type="button"
                            onClick={() => {
                                setSection(s);
                            }}
                            aria-current={s === section ? 'page' : undefined}
                            className={cx(
                                'border-b-2 px-3 py-2 text-ui',
                                s === section
                                    ? 'border-gold font-semibold text-ink'
                                    : 'border-transparent text-muted hover:text-ink',
                            )}
                        >
                            {s}
                        </button>
                    ))}
                </nav>

                {section === 'Definition' && (
                    <Panel>
                        <div className="flex flex-col gap-4">
                            <div className="flex flex-wrap gap-3">
                                <TextField
                                    label="Name"
                                    value={definition.data.name}
                                    onChange={(e) => {
                                        definition.setData('name', e.target.value);
                                    }}
                                />
                                <TextField
                                    label="Subject"
                                    value={definition.data.subject_type}
                                    onChange={(e) => {
                                        definition.setData('subject_type', e.target.value);
                                    }}
                                />
                                <TextField
                                    label="Starts"
                                    type="date"
                                    value={definition.data.starts_on}
                                    onChange={(e) => {
                                        definition.setData('starts_on', e.target.value);
                                    }}
                                />
                                <TextField
                                    label="Ends"
                                    type="date"
                                    value={definition.data.ends_on}
                                    onChange={(e) => {
                                        definition.setData('ends_on', e.target.value);
                                    }}
                                />
                                <TextField
                                    label="Target records"
                                    type="number"
                                    value={definition.data.target_record_count}
                                    onChange={(e) => {
                                        definition.setData('target_record_count', e.target.value);
                                    }}
                                />
                            </div>

                            <label className="flex flex-col gap-1">
                                <span className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                                    Objective
                                </span>
                                <textarea
                                    rows={2}
                                    value={definition.data.objective}
                                    onChange={(e) => {
                                        definition.setData('objective', e.target.value);
                                    }}
                                    className="w-full rounded-sm border border-rule-strong bg-surface px-3 py-2 text-ui text-ink"
                                />
                            </label>

                            <label className="flex flex-col gap-1">
                                <span className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                                    About, the dossier narrative
                                </span>
                                <textarea
                                    rows={10}
                                    value={definition.data.about}
                                    onChange={(e) => {
                                        definition.setData('about', e.target.value);
                                    }}
                                    className="w-full rounded-sm border border-rule-strong bg-surface px-3 py-2 text-body text-ink"
                                />
                            </label>

                            <label className="flex items-start gap-3 text-ui text-ink">
                                <input
                                    type="checkbox"
                                    checked={definition.data.materially_revised}
                                    onChange={(e) => {
                                        definition.setData('materially_revised', e.target.checked);
                                    }}
                                    className="mt-0.5 size-5 rounded-[2px] border-rule-strong accent-gold"
                                />
                                <span>
                                    This is a material revision
                                    <span className="block text-label text-faint">
                                        Everybody who has read the brief will be shown it again.
                                        Leave unticked for a typo: re-showing a modal for nothing
                                        teaches people to dismiss it without reading.
                                    </span>
                                </span>
                            </label>

                            <div>
                                <Button
                                    onClick={() => {
                                        definition.put(base, { preserveScroll: true });
                                    }}
                                    busy={definition.processing}
                                >
                                    Save definition
                                </Button>
                            </div>
                        </div>
                    </Panel>
                )}

                {section === 'Scope & areas' && (
                    <Panel>
                        <div className="flex flex-wrap items-end gap-3">
                            <SelectField
                                label="Bring a mandate into scope"
                                value={area.data.coverage_area_id}
                                onChange={(e) => {
                                    area.setData('coverage_area_id', e.target.value);
                                }}
                            >
                                <option value="">Choose ground</option>
                                {unassignedAreas.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.name}
                                    </option>
                                ))}
                            </SelectField>
                            <TextField
                                label="Its share of the target"
                                type="number"
                                value={area.data.target_record_count}
                                onChange={(e) => {
                                    area.setData('target_record_count', e.target.value);
                                }}
                            />
                            <Button
                                onClick={() => {
                                    area.post(`${base}/areas`, { preserveScroll: true });
                                }}
                                disabled={area.data.coverage_area_id === ''}
                            >
                                Add
                            </Button>
                        </div>

                        <p className="mt-2 max-w-[68ch] text-label text-faint">
                            A mandate is ground the field platform already owns: its grid, its
                            offline map packs and every structure captured inside it. Adding it here
                            links it to this exercise and changes nothing else about it.
                        </p>

                        <ul className="mt-5 flex flex-col border-t border-rule">
                            {campaign.coverage.areas.map((row) => (
                                <li
                                    key={row.id}
                                    className="flex flex-wrap items-baseline gap-x-6 gap-y-1 border-b border-rule py-2.5"
                                >
                                    <span className="min-w-[180px] flex-1 text-ui text-ink">
                                        {row.name}
                                    </span>
                                    <span className="text-label text-muted">
                                        {row.state ?? '.'} &middot; {row.lga ?? '.'}
                                    </span>
                                    <span className="numeric-mono text-label text-faint">
                                        {row.areaKm2} km2 &middot; {row.cells.toLocaleString()} cells
                                    </span>
                                    <span className="numeric-mono text-label text-faint">
                                        {row.targetRecordCount?.toLocaleString() ?? 'no target'}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            router.delete(`${base}/areas/${String(row.id)}`, {
                                                preserveScroll: true,
                                            });
                                        }}
                                        className="text-label text-gold underline underline-offset-2"
                                    >
                                        Remove
                                    </button>
                                </li>
                            ))}
                            {campaign.coverage.areas.length === 0 && (
                                <li className="py-4 text-ui text-muted">
                                    No ground is in scope yet.
                                </li>
                            )}
                        </ul>
                    </Panel>
                )}

                {section === 'Data schema' && (
                    <Panel>
                        <div className="flex flex-wrap items-end gap-3">
                            <TextField
                                label="Label"
                                value={field.data.label}
                                onChange={(e) => {
                                    field.setData('label', e.target.value);
                                    if (field.data.key === '') {
                                        field.setData(
                                            'key',
                                            e.target.value
                                                .toLowerCase()
                                                .replace(/[^a-z0-9]+/g, '_')
                                                .replace(/^_|_$/g, ''),
                                        );
                                    }
                                }}
                            />
                            <TextField
                                label="Key"
                                value={field.data.key}
                                onChange={(e) => {
                                    field.setData('key', e.target.value);
                                }}
                            />
                            <SelectField
                                label="Type"
                                value={field.data.type}
                                onChange={(e) => {
                                    field.setData('type', e.target.value);
                                }}
                            >
                                {vocabulary.fieldTypes.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </SelectField>
                            <TextField
                                label="Options, comma separated"
                                value={field.data.options}
                                onChange={(e) => {
                                    field.setData('options', e.target.value);
                                }}
                            />
                            <label className="flex min-h-touch items-center gap-2 text-ui text-ink">
                                <input
                                    type="checkbox"
                                    checked={field.data.is_required}
                                    onChange={(e) => {
                                        field.setData('is_required', e.target.checked);
                                    }}
                                    className="size-5 rounded-[2px] border-rule-strong accent-gold"
                                />
                                Required
                            </label>
                            <Button
                                onClick={() => {
                                    // Transform returns void in this Inertia
                                    // version, so it is applied and then posted
                                    // rather than chained.
                                    field.transform((data) => ({
                                        ...data,
                                        options: data.options
                                            .split(',')
                                            .map((o) => o.trim())
                                            .filter((o) => o !== ''),
                                    }));
                                    field.post(`${base}/fields`, { preserveScroll: true });
                                }}
                                disabled={field.data.label === '' || field.data.key === ''}
                            >
                                Save field
                            </Button>
                        </div>

                        {Object.values(field.errors).map((error) => (
                            <p key={error} className="mt-2 text-label text-alert">
                                {error}
                            </p>
                        ))}

                        <ul className="mt-5 flex flex-col border-t border-rule">
                            {campaign.schema.fields.map((row) => (
                                <li
                                    key={row.id}
                                    className="flex flex-wrap items-baseline gap-x-6 gap-y-1 border-b border-rule py-2.5"
                                >
                                    <span className="min-w-[180px] flex-1 text-ui text-ink">
                                        {row.label}
                                    </span>
                                    <span className="numeric-mono text-label text-faint">
                                        {row.key}
                                    </span>
                                    <span className="text-label text-muted">{row.typeLabel}</span>
                                    <span className="numeric-mono text-label text-faint">
                                        {row.isRequired ? 'required' : 'optional'}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            router.delete(`${base}/fields/${String(row.id)}`, {
                                                preserveScroll: true,
                                            });
                                        }}
                                        className="text-label text-gold underline underline-offset-2"
                                    >
                                        Remove
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </Panel>
                )}

                {section === 'Stakeholders' && (
                    <Panel>
                        <div className="flex flex-wrap items-end gap-3">
                            <TextField
                                label="Name"
                                value={stakeholder.data.name}
                                onChange={(e) => {
                                    stakeholder.setData('name', e.target.value);
                                }}
                            />
                            <SelectField
                                label="Category"
                                value={stakeholder.data.category}
                                onChange={(e) => {
                                    stakeholder.setData('category', e.target.value);
                                }}
                            >
                                {vocabulary.categories.map((category) => (
                                    <option key={category.value} value={category.value}>
                                        {category.label}
                                    </option>
                                ))}
                            </SelectField>
                            <TextField
                                label="Organisation"
                                value={stakeholder.data.organisation}
                                onChange={(e) => {
                                    stakeholder.setData('organisation', e.target.value);
                                }}
                            />
                            <SelectField
                                label="Engagement"
                                value={stakeholder.data.engagement_status}
                                onChange={(e) => {
                                    stakeholder.setData('engagement_status', e.target.value);
                                }}
                            >
                                {vocabulary.engagement.map((status) => (
                                    <option key={status.value} value={status.value}>
                                        {status.label}
                                    </option>
                                ))}
                            </SelectField>
                            <label className="flex min-h-touch items-center gap-2 text-ui text-ink">
                                <input
                                    type="checkbox"
                                    checked={stakeholder.data.visible_to_client}
                                    onChange={(e) => {
                                        stakeholder.setData('visible_to_client', e.target.checked);
                                    }}
                                    className="size-5 rounded-[2px] border-rule-strong accent-gold"
                                />
                                Client can see this
                            </label>
                            <Button
                                onClick={() => {
                                    stakeholder.post(`${base}/stakeholders`, {
                                        preserveScroll: true,
                                    });
                                }}
                                disabled={stakeholder.data.name === ''}
                            >
                                Save
                            </Button>
                        </div>

                        <div className="mt-5 flex flex-col gap-5">
                            {campaign.stakeholders.byCategory.map((group) => (
                                <div key={group.category}>
                                    <p className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                                        {group.label}
                                    </p>
                                    <ul className="mt-1 flex flex-col border-t border-rule">
                                        {group.people.map((person) => (
                                            <li
                                                key={person.id}
                                                className="flex flex-wrap items-baseline gap-x-6 gap-y-1 border-b border-rule py-2"
                                            >
                                                <span className="min-w-[160px] flex-1 text-ui text-ink">
                                                    {person.name}
                                                </span>
                                                <span className="text-label text-muted">
                                                    {person.organisation ?? ''}
                                                </span>
                                                <span className="numeric-mono text-label text-faint">
                                                    {person.engagementLabel}
                                                </span>
                                                {!person.visibleToClient && (
                                                    <StatusPill
                                                        tone="held"
                                                        label="Internal"
                                                        size="sm"
                                                    />
                                                )}
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        router.delete(
                                                            `${base}/stakeholders/${String(person.id)}`,
                                                            { preserveScroll: true },
                                                        );
                                                    }}
                                                    className="text-label text-gold underline underline-offset-2"
                                                >
                                                    Remove
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </div>
                    </Panel>
                )}

                {section === 'Deployment' && (
                    <Panel>
                        <div className="flex flex-wrap items-end gap-3">
                            <SelectField
                                label="Officers"
                                multiple
                                size="console"
                                value={deploy.data.user_ids.map(String)}
                                onChange={(e) => {
                                    deploy.setData(
                                        'user_ids',
                                        [...e.target.selectedOptions].map((o) => Number(o.value)),
                                    );
                                }}
                            >
                                {officers.map((officer) => (
                                    <option key={officer.id} value={officer.id}>
                                        {officer.name} {officer.staffRef ?? ''}
                                    </option>
                                ))}
                            </SelectField>

                            <SelectField
                                label="Onto which area"
                                value={deploy.data.coverage_area_id}
                                onChange={(e) => {
                                    deploy.setData('coverage_area_id', e.target.value);
                                }}
                            >
                                <option value="">Not yet decided</option>
                                {campaign.coverage.areas.map((row) => (
                                    <option key={row.id} value={row.id}>
                                        {row.name}
                                    </option>
                                ))}
                            </SelectField>

                            <Button
                                onClick={() => {
                                    deploy.post(`${base}/deploy`, { preserveScroll: true });
                                }}
                                disabled={deploy.data.user_ids.length === 0}
                            >
                                Deploy
                            </Button>
                        </div>

                        {Object.values(deploy.errors).map((error) => (
                            <p key={error} className="mt-2 text-label text-alert">
                                {error}
                            </p>
                        ))}

                        <ul className="mt-5 flex flex-col border-t border-rule">
                            {campaign.deployment.roster.map((agent) => (
                                <li
                                    key={agent.id}
                                    className="flex flex-wrap items-baseline gap-x-6 gap-y-1 border-b border-rule py-2.5"
                                >
                                    <span className="min-w-[160px] flex-1 text-ui text-ink">
                                        {agent.name}
                                    </span>
                                    <span className="numeric-mono text-label text-faint">
                                        {agent.staffRef ?? '.'}
                                    </span>
                                    <span className="text-label text-muted">
                                        {agent.area ?? 'no area yet'}
                                    </span>
                                    <span className="numeric-mono text-label text-faint">
                                        since {on(agent.assignedAt)}
                                    </span>
                                </li>
                            ))}
                            {campaign.deployment.roster.length === 0 && (
                                <li className="py-4 text-ui text-muted">Nobody is deployed yet.</li>
                            )}
                        </ul>
                    </Panel>
                )}

                {section === 'Commercials' && commercial !== null && (
                    <Panel>
                        <p className="mb-4 max-w-[68ch] border-l-2 border-alert bg-raised px-3 py-2 text-ui text-muted">
                            Nothing on this tab is ever sent to the client. It lives in its own
                            table, and the action that builds every client screen has no code path
                            to it.
                        </p>

                        <div className="flex flex-wrap items-end gap-3">
                            <TextField
                                label="Contract value"
                                type="number"
                                value={money.data.contract_value}
                                onChange={(e) => {
                                    money.setData('contract_value', e.target.value);
                                }}
                            />
                            <TextField
                                label="Currency"
                                value={money.data.currency}
                                onChange={(e) => {
                                    money.setData('currency', e.target.value.toUpperCase());
                                }}
                            />
                            <SelectField
                                label="Payment"
                                value={money.data.payment_status}
                                onChange={(e) => {
                                    money.setData('payment_status', e.target.value);
                                }}
                            >
                                {vocabulary.payment.map((status) => (
                                    <option key={status.value} value={status.value}>
                                        {status.label}
                                    </option>
                                ))}
                            </SelectField>
                            <TextField
                                label="Paid on"
                                type="date"
                                value={money.data.paid_at}
                                onChange={(e) => {
                                    money.setData('paid_at', e.target.value);
                                }}
                            />
                        </div>

                        <label className="mt-4 flex flex-col gap-1">
                            <span className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                                Internal notes
                            </span>
                            <textarea
                                rows={5}
                                value={money.data.internal_notes}
                                onChange={(e) => {
                                    money.setData('internal_notes', e.target.value);
                                }}
                                className="w-full rounded-sm border border-rule-strong bg-surface px-3 py-2 text-ui text-ink"
                            />
                        </label>

                        <div className="mt-4">
                            <Button
                                onClick={() => {
                                    money.post(`${base}/commercials`, { preserveScroll: true });
                                }}
                                busy={money.processing}
                            >
                                Save commercials
                            </Button>
                        </div>
                    </Panel>
                )}

                {section === 'Review' && (
                    <Panel>
                        <div className="grid gap-6 md:grid-cols-2">
                            <Progress
                                label="Timeline"
                                value={
                                    campaign.timeline.daysRemaining === null
                                        ? 'no end date'
                                        : `${String(Math.max(0, campaign.timeline.daysRemaining))} days left`
                                }
                                percent={campaign.timeline.elapsedPercent}
                                caption={`${on(campaign.timeline.startsOn)} to ${on(campaign.timeline.endsOn)}`}
                            />
                            <Progress
                                label="Records gathered"
                                value={campaign.collection.gathered.toLocaleString()}
                                total={campaign.collection.target?.toLocaleString() ?? null}
                                percent={campaign.collection.percent}
                                tone="green"
                            />
                        </div>

                        <dl className="mt-6 grid gap-x-8 gap-y-3 border-t border-rule pt-5 sm:grid-cols-3">
                            {[
                                ['Areas in scope', String(campaign.coverage.areaCount)],
                                ['Schema fields', String(campaign.schema.fieldCount)],
                                ['Stakeholders', String(campaign.stakeholders.total)],
                                ['Agents deployed', String(campaign.deployment.activeCount)],
                                ['States', campaign.coverage.states.join(', ') || 'none yet'],
                                ['Client', campaign.client.name ?? ''],
                            ].map(([label, value]) => (
                                <div key={label}>
                                    <dt className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                                        {label}
                                    </dt>
                                    <dd className="numeric-mono text-mono text-ink">{value}</dd>
                                </div>
                            ))}
                        </dl>

                        <p className="mt-6 max-w-[68ch] text-ui text-muted">
                            A campaign is ready to approve when it has ground in scope, a declared
                            schema, and the stakeholders who have to be squared before officers
                            walk. None of those is enforced: a short exercise on known ground may
                            legitimately have none of the third.
                        </p>
                    </Panel>
                )}
            </div>
        </ConsoleShell>
    );
}
