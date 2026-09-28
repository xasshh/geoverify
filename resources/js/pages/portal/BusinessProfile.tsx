import { Head, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { TextField } from '@/components/Field';
import { PortalShell } from '@/components/PortalShell';
import { cx } from '@/lib/cx';

type Day = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun';
type Hours = Record<Day, { opens: string; closes: string }>;

const DAYS: [Day, string][] = [
    ['mon', 'Monday'],
    ['tue', 'Tuesday'],
    ['wed', 'Wednesday'],
    ['thu', 'Thursday'],
    ['fri', 'Friday'],
    ['sat', 'Saturday'],
    ['sun', 'Sunday'],
];

interface Props {
    business: { id: number; name: string };
    profile: { hours: Partial<Record<Day, { opens: string; closes: string } | null>> | null; delivers: boolean | null; address: string | null };
    canEdit: boolean;
}

/**
 * Hours, delivery and address: what the directory's "Open now", "Delivers" and
 * Directions read. A day left empty is closed.
 */
export default function BusinessProfile({ business, profile, canEdit }: Props) {
    const form = useForm<{ hours: Hours; delivers: boolean | null; address: string }>({
        hours: Object.fromEntries(DAYS.map(([key]) => [key, { opens: profile.hours?.[key]?.opens ?? '', closes: profile.hours?.[key]?.closes ?? '' }])) as Hours,
        delivers: profile.delivers,
        address: profile.address ?? '',
    });
    const errors = usePage().props.errors as Record<string, string | undefined>;

    const setDay = (day: Day, field: 'opens' | 'closes', value: string) => {
        form.setData('hours', { ...form.data.hours, [day]: { ...form.data.hours[day], [field]: value } });
    };

    return (
        <PortalShell accountName={usePage().props.auth.portal?.name ?? ''} width="page" kicker={business.name} title="Hours & delivery" subtitle="What buyers see on your listing, and what the directory's filters use.">
            <Head title="Hours & delivery" />
            <form
                className="flex max-w-3xl flex-col gap-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/portal/businesses/${String(business.id)}/profile`, { preserveScroll: true });
                }}
            >
                <section className="rounded-card border border-rule bg-raised px-6 py-6">
                    <h2 className="font-display text-display-s text-ink">Opening hours</h2>
                    <p className="mt-1 max-w-none text-ui text-muted">In Nigerian time. Leave a day empty if you are closed. “Open now” in the directory reads these.</p>
                    <div className="mt-4 flex flex-col gap-2">
                        {DAYS.map(([key, name]) => (
                            <div key={key} className="grid grid-cols-[110px_1fr_1fr] items-center gap-3">
                                <span className="text-ui font-bold text-ink">{name}</span>
                                <input
                                    type="time"
                                    aria-label={`${name} opens`}
                                    disabled={!canEdit}
                                    value={form.data.hours[key].opens}
                                    onChange={(e) => {
                                        setDay(key, 'opens', e.target.value);
                                    }}
                                    className="h-11 rounded-sm border border-rule-strong bg-raised px-3 text-ui text-ink"
                                />
                                <input
                                    type="time"
                                    aria-label={`${name} closes`}
                                    disabled={!canEdit}
                                    value={form.data.hours[key].closes}
                                    onChange={(e) => {
                                        setDay(key, 'closes', e.target.value);
                                    }}
                                    className="h-11 rounded-sm border border-rule-strong bg-raised px-3 text-ui text-ink"
                                />
                            </div>
                        ))}
                    </div>
                    {errors.hours !== undefined && <p className="mt-3 text-ui text-alert">{errors.hours}</p>}
                </section>

                <section className="rounded-card border border-rule bg-raised px-6 py-6">
                    <h2 className="font-display text-display-s text-ink">Delivery</h2>
                    <div className="mt-3 flex flex-wrap gap-2" role="radiogroup" aria-label="Delivery">
                        {(
                            [
                                [true, 'We deliver'],
                                [false, 'Collection from the shop only'],
                                [null, 'Not stated'],
                            ] as const
                        ).map(([value, label]) => (
                            <button
                                key={label}
                                type="button"
                                role="radio"
                                aria-checked={form.data.delivers === value}
                                disabled={!canEdit}
                                onClick={() => {
                                    form.setData('delivers', value);
                                }}
                                className={cx('min-h-touch rounded-sm border px-4 text-ui font-bold', form.data.delivers === value ? 'border-gold bg-gold-soft text-gold-dark' : 'border-rule-strong text-ink')}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <p className="mt-2 max-w-none text-table text-muted">Collection only means buyers pay no delivery fee and pick their order up from you.</p>
                </section>

                <section className="rounded-card border border-rule bg-raised px-6 py-6">
                    <TextField
                        label="Street address"
                        hint="Published on your listing. Directions search for your name and this address; we never publish your map position."
                        value={form.data.address}
                        disabled={!canEdit}
                        maxLength={200}
                        onChange={(e) => {
                            form.setData('address', e.target.value);
                        }}
                    />
                </section>

                {canEdit && (
                    <div>
                        <Button type="submit" variant="primary" size="field" busy={form.processing}>
                            Save
                        </Button>
                    </div>
                )}
            </form>
        </PortalShell>
    );
}
