import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/Button';
import { TextField } from '@/components/Field';
import { GeoVerifyLockup } from '@/components/GeoVerifyMark';
import { setQuantity, useCart } from '@/lib/cart';
import { cx } from '@/lib/cx';

interface Product {
    id: number;
    name: string;
    unit: string | null;
    priceNaira: number | null;
}

interface ProtectionOption {
    value: 'none' | 'inspection' | 'site_visit';
    label: string;
    feeNaira: number | null;
    offered: boolean;
}

interface Props {
    business: { id: number; name: string; place: string; verified: boolean };
    products: Product[];
    deliveryNaira: number;
    protections: ProtectionOption[];
    channels: { value: string; label: string }[];
}

const STEPS = ['Cart', 'Delivery', 'Protection & pay', 'Held until delivery'] as const;

const PROTECTION_COPY: Record<ProtectionOption['value'], string> = {
    inspection:
        'The nearest GeoVerify field agent checks quantity, quality and packaging at the shop, then uploads a geo-tagged photo report. You approve the report before it ships.',
    site_visit:
        'Visit the business with an agent at a time you choose, or send an agent on your behalf with a live video call.',
    none: 'Your payment is still held until you confirm delivery.',
};

function naira(amount: number): string {
    return `₦${amount.toLocaleString('en-NG')}`;
}

/**
 * Checkout, one business at a time, to the mockup's four steps.
 *
 * The lines come from the buyer's browser, but only those still listed and
 * priced on the business's published catalogue are shown or sent, and the
 * server prices them again: the totals on this page are a preview of the
 * arithmetic PlacePurchase does, never an input to it.
 */
export default function Checkout({ business, products, deliveryNaira, protections, channels }: Props) {
    const page = usePage();
    const errors = page.props.errors as Record<string, string | undefined>;
    const account = page.props.auth.portal;
    const cart = useCart(business.id);

    const listed = new Map(products.map((p) => [p.id, p]));
    const lines = (cart?.lines ?? [])
        .filter((line) => listed.has(line.id))
        .map((line) => {
            const current = listed.get(line.id);

            return { ...line, priceNaira: current?.priceNaira ?? line.priceNaira, unit: current?.unit ?? line.unit };
        });
    const dropped = (cart?.lines.length ?? 0) - lines.length;

    const [step, setStep] = useState(0);
    const [delivery, setDelivery] = useState({ name: account?.name ?? '', phone: '', address: '', note: '' });
    const [protection, setProtection] = useState<ProtectionOption['value']>('none');
    const [channel, setChannel] = useState(channels[0]?.value ?? 'card');
    const [visitAt, setVisitAt] = useState('');
    // An hour from when the page opened, worked out once rather than on every render.
    const [earliestVisit] = useState(() => new Date(Date.now() + 3_600_000).toISOString().slice(0, 16));
    const [visitMode, setVisitMode] = useState<'with_me' | 'for_me'>('with_me');
    const [busy, setBusy] = useState(false);

    const itemsNaira = lines.reduce((n, line) => n + line.priceNaira * line.quantity, 0);
    const itemCount = lines.reduce((n, line) => n + line.quantity, 0);
    const chosen = protections.find((p) => p.value === protection);
    const serviceNaira = chosen?.feeNaira ?? 0;
    const totalNaira = itemsNaira + deliveryNaira + serviceNaira;
    const deliveryReady = delivery.name.trim() !== '' && delivery.phone.trim() !== '' && delivery.address.trim() !== '';

    const pay = () => {
        setBusy(true);
        router.post(
            `/portal/checkout/${String(business.id)}`,
            {
                items: lines.map((line) => ({ id: line.id, quantity: line.quantity })),
                delivery,
                protection,
                channel,
                ...(protection === 'site_visit' ? { visit_at: visitAt, visit_mode: visitMode } : {}),
            },
            {
                onFinish: () => {
                    setBusy(false);
                },
            },
        );
    };

    return (
        <div data-mode="daylight" className="min-h-dvh bg-surface text-ink">
            <Head title="Checkout" />
            <header className="border-b border-rule bg-raised">
                <div className="mx-auto flex min-h-[76px] max-w-6xl flex-wrap items-center justify-between gap-4 px-5 py-3">
                    <Link href={`/directory/${String(business.id)}`}>
                        <GeoVerifyLockup size={36} />
                    </Link>
                    <ol className="flex list-none flex-wrap items-center gap-x-5 gap-y-2 p-0" aria-label="Checkout steps">
                        {STEPS.map((label, i) => (
                            <li
                                key={label}
                                aria-current={i === step ? 'step' : undefined}
                                className={cx(
                                    'flex items-center gap-2 text-ui font-bold',
                                    i === step ? 'text-ink' : i < step ? 'text-gold-dark' : 'text-faint',
                                )}
                            >
                                <span
                                    className={cx(
                                        'flex size-6 items-center justify-center rounded-full text-label',
                                        i === step ? 'bg-ink text-inverse' : i < step ? 'bg-gold-soft text-gold-dark' : 'border border-rule-strong',
                                    )}
                                >
                                    {i + 1}
                                </span>
                                {label}
                            </li>
                        ))}
                    </ol>
                    <p className="text-ui font-bold text-muted">Secure checkout</p>
                </div>
            </header>

            <main className="mx-auto grid max-w-6xl gap-8 px-5 pt-8 pb-24 lg:grid-cols-[minmax(0,1fr)_380px]">
                <div className="flex min-w-0 flex-col gap-6">
                    {(errors.checkout !== undefined || errors.payment !== undefined) && (
                        <p role="alert" className="rounded-sm bg-alert-soft px-4 py-3 text-ui font-semibold text-alert-ink">
                            {errors.checkout ?? errors.payment}
                        </p>
                    )}

                    <section className="rounded-card border border-rule bg-raised px-6 py-6">
                        <div className="flex items-start justify-between gap-4">
                            <div className="flex items-center gap-3">
                                <span className="flex size-11 items-center justify-center rounded-sm bg-gold-soft font-extrabold text-gold-dark">
                                    {business.name.slice(0, 2).toUpperCase()}
                                </span>
                                <div>
                                    <p className="text-body font-extrabold text-ink">{business.name}</p>
                                    <p className="text-table text-muted">
                                        {business.verified ? 'GeoVerified' : 'Claimed by its owner'}
                                        {business.place !== '' && ` · ${business.place}`}
                                    </p>
                                </div>
                            </div>
                            {step > 0 && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setStep(0);
                                    }}
                                    className="text-ui font-bold text-gold hover:text-gold-dark"
                                >
                                    Edit cart
                                </button>
                            )}
                        </div>

                        {lines.length === 0 ? (
                            <div className="mt-6 text-ui text-muted">
                                <p>Your cart for this business is empty.</p>
                                <Link href={`/directory/${String(business.id)}`} className="mt-2 inline-block font-bold text-gold">
                                    Back to its products
                                </Link>
                            </div>
                        ) : (
                            <ul className="mt-5 list-none divide-y divide-rule p-0">
                                {lines.map((line) => (
                                    <li key={line.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                        <span className="text-ui text-ink">
                                            <span className="font-bold">{line.name}</span>
                                            {line.unit !== null && ` · ${line.unit}`}
                                            {step > 0 && ` × ${String(line.quantity)}`}
                                        </span>
                                        <span className="flex items-center gap-4">
                                            {step === 0 && (
                                                <label className="flex items-center gap-2 text-table text-muted">
                                                    Qty
                                                    <input
                                                        type="number"
                                                        min={0}
                                                        max={999}
                                                        value={line.quantity}
                                                        onChange={(e) => {
                                                            setQuantity(business.id, line.id, Number(e.target.value));
                                                        }}
                                                        className="h-11 w-20 rounded-sm border border-rule-strong bg-raised px-2 text-ui text-ink"
                                                    />
                                                </label>
                                            )}
                                            <span className="min-w-24 text-right font-extrabold text-ink">
                                                {naira(line.priceNaira * line.quantity)}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {dropped > 0 && (
                            <p className="mt-3 text-table text-muted">
                                {dropped === 1 ? 'One item' : `${String(dropped)} items`} in your cart are no longer listed and have been left out.
                            </p>
                        )}
                    </section>

                    {step === 0 && lines.length > 0 && (
                        <div>
                            <Button
                                variant="primary"
                                size="field"
                                onClick={() => {
                                    setStep(1);
                                }}
                            >
                                Continue to delivery
                            </Button>
                        </div>
                    )}

                    {step === 1 && (
                        <section className="rounded-card border border-rule bg-raised px-6 py-6">
                            <h2 className="font-display text-display-s text-ink">Where should it go?</h2>
                            <p className="mt-1 text-ui text-muted">Given to {business.name} so they can deliver, and for nothing else.</p>
                            <div className="mt-5 grid gap-4 sm:grid-cols-2">
                                <TextField
                                    label="Name"
                                    value={delivery.name}
                                    autoComplete="name"
                                    onChange={(e) => {
                                        setDelivery({ ...delivery, name: e.target.value });
                                    }}
                                    {...(errors['delivery.name'] === undefined ? {} : { error: errors['delivery.name'] })}
                                />
                                <TextField
                                    label="Phone"
                                    value={delivery.phone}
                                    inputMode="tel"
                                    autoComplete="tel"
                                    onChange={(e) => {
                                        setDelivery({ ...delivery, phone: e.target.value });
                                    }}
                                    {...(errors['delivery.phone'] === undefined ? {} : { error: errors['delivery.phone'] })}
                                />
                                <div className="sm:col-span-2">
                                    <TextField
                                        label="Delivery address"
                                        value={delivery.address}
                                        autoComplete="street-address"
                                        onChange={(e) => {
                                            setDelivery({ ...delivery, address: e.target.value });
                                        }}
                                        {...(errors['delivery.address'] === undefined ? {} : { error: errors['delivery.address'] })}
                                    />
                                </div>
                                <div className="sm:col-span-2">
                                    <TextField
                                        label="Note for the rider"
                                        hint="Optional. A landmark, a gate, a time to avoid."
                                        value={delivery.note}
                                        onChange={(e) => {
                                            setDelivery({ ...delivery, note: e.target.value });
                                        }}
                                    />
                                </div>
                            </div>
                            <div className="mt-6">
                                <Button
                                    variant="primary"
                                    size="field"
                                    disabled={!deliveryReady}
                                    onClick={() => {
                                        setStep(2);
                                    }}
                                >
                                    Continue to protection and payment
                                </Button>
                            </div>
                        </section>
                    )}

                    {step === 2 && (
                        <>
                            <section>
                                <h2 className="font-display text-display-s text-ink">Add a verification service</h2>
                                <div className="mt-4 flex flex-col gap-3" role="radiogroup" aria-label="Verification service">
                                    {[...protections].sort((a, b) => (a.value === 'none' ? 1 : b.value === 'none' ? -1 : 0)).map((p) => (
                                        <label
                                            key={p.value}
                                            className={cx(
                                                'flex gap-4 rounded-card border bg-raised px-5 py-5',
                                                protection === p.value ? 'border-gold ring-2 ring-gold/30' : 'border-rule',
                                                p.offered ? 'cursor-pointer' : 'cursor-not-allowed opacity-70',
                                            )}
                                        >
                                            <input
                                                type="radio"
                                                name="protection"
                                                value={p.value}
                                                checked={protection === p.value}
                                                disabled={!p.offered}
                                                onChange={() => {
                                                    setProtection(p.value);
                                                }}
                                                className="mt-1 size-5 accent-[var(--color-gold)]"
                                            />
                                            <span className="flex-1">
                                                <span className="flex flex-wrap items-baseline justify-between gap-2">
                                                    <span className="text-body font-extrabold text-ink">
                                                        {p.value === 'inspection'
                                                            ? 'Product inspection before dispatch'
                                                            : p.value === 'site_visit'
                                                              ? 'Book a site visit'
                                                              : p.label}
                                                    </span>
                                                    {p.value !== 'none' && (
                                                        <span className="text-ui font-bold text-muted">
                                                            {p.offered && p.feeNaira !== null ? naira(p.feeNaira) : 'Coming soon'}
                                                        </span>
                                                    )}
                                                </span>
                                                <span className="mt-1 block text-ui text-muted">{PROTECTION_COPY[p.value]}</span>
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            </section>

                            {protection === 'site_visit' && (
                                <section className="rounded-card border border-rule bg-raised px-5 py-5">
                                    <h2 className="text-body font-extrabold text-ink">Arrange the visit</h2>
                                    <div className="mt-3 grid gap-4 sm:grid-cols-2">
                                        <label className="flex flex-col gap-1.5 text-label font-bold tracking-[0.05em] text-muted uppercase">
                                            When
                                            <input
                                                type="datetime-local"
                                                value={visitAt}
                                                min={earliestVisit}
                                                onChange={(e) => {
                                                    setVisitAt(e.target.value);
                                                }}
                                                className="h-11 rounded-sm border border-rule-strong bg-raised px-3 text-ui font-normal tracking-normal text-ink normal-case"
                                            />
                                        </label>
                                        <div className="flex flex-col gap-1.5">
                                            <span className="text-label font-bold tracking-[0.05em] text-muted uppercase">The agent</span>
                                            <div className="flex gap-2" role="radiogroup" aria-label="The agent">
                                                {(
                                                    [
                                                        ['with_me', 'Goes with me'],
                                                        ['for_me', 'Goes for me'],
                                                    ] as const
                                                ).map(([value, label]) => (
                                                    <button
                                                        key={value}
                                                        type="button"
                                                        role="radio"
                                                        aria-checked={visitMode === value}
                                                        onClick={() => {
                                                            setVisitMode(value);
                                                        }}
                                                        className={cx('min-h-11 flex-1 rounded-sm border text-ui font-bold', visitMode === value ? 'border-gold bg-gold-soft text-gold-dark' : 'border-rule-strong text-ink')}
                                                    >
                                                        {label}
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                    {(errors.visit_at ?? errors.visit_mode) !== undefined && <p className="mt-2 text-ui text-alert">{errors.visit_at ?? errors.visit_mode}</p>}
                                </section>
                            )}

                            <section>
                                <h2 className="font-display text-display-s text-ink">Pay with</h2>
                                <div className="mt-4 grid gap-3 sm:grid-cols-3" role="radiogroup" aria-label="Payment method">
                                    {channels.map((c) => (
                                        <label
                                            key={c.value}
                                            className={cx(
                                                'flex min-h-touch-lg cursor-pointer items-center gap-3 rounded-card border bg-raised px-4',
                                                channel === c.value ? 'border-gold ring-2 ring-gold/30' : 'border-rule',
                                            )}
                                        >
                                            <input
                                                type="radio"
                                                name="channel"
                                                value={c.value}
                                                checked={channel === c.value}
                                                onChange={() => {
                                                    setChannel(c.value);
                                                }}
                                                className="size-5 accent-[var(--color-gold)]"
                                            />
                                            <span className="text-ui font-bold text-ink">{c.label}</span>
                                        </label>
                                    ))}
                                </div>
                            </section>
                        </>
                    )}
                </div>

                <aside className="flex flex-col gap-5">
                    <section className="rounded-card border border-rule bg-raised px-6 py-6 shadow-card" aria-labelledby="summary">
                        <h2 id="summary" className="font-display text-display-s text-ink">
                            Order summary
                        </h2>
                        <dl className="mt-4 flex flex-col gap-3 text-ui">
                            <div className="flex justify-between gap-3">
                                <dt className="text-muted">Items ({itemCount})</dt>
                                <dd className="font-bold text-ink">{naira(itemsNaira)}</dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-muted">Delivery</dt>
                                <dd className="font-bold text-ink">{naira(deliveryNaira)}</dd>
                            </div>
                            {protection !== 'none' && (
                                <div className="flex justify-between gap-3">
                                    <dt className="text-muted">{chosen?.label}</dt>
                                    <dd className="font-bold text-ink">{naira(serviceNaira)}</dd>
                                </div>
                            )}
                            <div className="flex justify-between gap-3">
                                <dt className="text-muted">Held until delivery</dt>
                                <dd className="font-bold text-green">Included</dd>
                            </div>
                            <div className="mt-1 flex justify-between gap-3 border-t border-rule pt-4">
                                <dt className="text-body font-extrabold text-ink">Total</dt>
                                <dd className="font-display text-display-s text-ink">{naira(totalNaira)}</dd>
                            </div>
                        </dl>
                        {step === 2 && (
                            <div className="mt-5">
                                <Button variant="primary" size="field-primary" fullWidth busy={busy} disabled={lines.length === 0 || (protection === 'site_visit' && visitAt === '')} onClick={pay}>
                                    Pay and hold {naira(totalNaira)}
                                </Button>
                            </div>
                        )}
                        <p className="mt-3 text-table text-muted">The merchant is not paid until you confirm you received your order.</p>
                    </section>

                    <section className="rounded-card border border-rule bg-raised px-6 py-6" aria-labelledby="next">
                        <h2 id="next" className="text-label font-extrabold tracking-[0.05em] text-muted uppercase">
                            What happens next
                        </h2>
                        <ol className="mt-4 flex list-none flex-col gap-4 p-0">
                            {(
                                [
                                    ['You pay', 'GeoVerify holds the money safely'],
                                    ['An agent inspects the goods', 'If you added inspection: a geo-tagged photo report for your approval'],
                                    ['The merchant dispatches', 'You are told when it is on the way'],
                                    ['You confirm delivery', 'Only then are funds released to the merchant'],
                                ] as const
                            ).map(([title, detail], i) => (
                                <li key={title} className="flex gap-3">
                                    <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-gold-soft text-label font-extrabold text-gold-dark">
                                        {i + 1}
                                    </span>
                                    <span>
                                        <span className="block text-ui font-bold text-ink">{title}</span>
                                        <span className="block text-table text-muted">{detail}</span>
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </section>
                </aside>
            </main>
        </div>
    );
}
