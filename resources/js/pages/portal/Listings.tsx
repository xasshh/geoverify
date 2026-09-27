import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Button } from '@/components/Button';
import { TextField } from '@/components/Field';
import { PortalShell } from '@/components/PortalShell';
import { cx } from '@/lib/cx';

interface Product {
    id: number;
    name: string;
    unit: string | null;
    priceNaira: number | null;
    description: string | null;
    status: 'active' | 'hidden';
    photos: { id: number; url: string }[];
}

interface Props {
    business: { id: number; name: string; published: boolean };
    canEdit: boolean;
    strength: { percent: number; missing: string[]; hint: string };
    products: Product[];
    maxPhotos: number;
}

function naira(n: number | null): string {
    return n === null ? 'Price on request' : `₦${n.toLocaleString('en-NG')}`;
}

/** Soft tints for a product with no photograph yet, as the mockup's cards. */
const TINTS = ['bg-[#EEF1E8]', 'bg-[#F4E6E4]', 'bg-[#F7EEDD]', 'bg-[#EFE8E0]', 'bg-[#F0EAE3]', 'bg-[#EEF3E2]'];

/**
 * Listings: what this business sells, in its own words and photographs.
 *
 * Public on the business's profile once the listing is published, never
 * before. A viewer on the team sees this page and cannot change it.
 */
export default function Listings({ business, canEdit, strength, products, maxPhotos }: Props) {
    const page = usePage();
    const accountName = page.props.auth.portal?.name ?? business.name;
    const photoError = page.props.errors.photo;
    const [adding, setAdding] = useState(false);
    const base = `/portal/businesses/${String(business.id)}/listings`;

    return (
        <PortalShell
            accountName={accountName}
            width="page"
            title="Listings"
            subtitle={`${business.name} · ${String(products.length)} ${products.length === 1 ? 'product' : 'products'}`}
            actions={
                canEdit ? (
                    <Button
                        variant="primary"
                        size="field"
                        onClick={() => {
                            setAdding(true);
                        }}
                    >
                        + Add a product
                    </Button>
                ) : undefined
            }
        >
            <Head title="Listings" />

            {photoError !== undefined && (
                <p className="mb-5 rounded-sm bg-alert-soft px-4 py-3 text-ui text-alert-ink">{photoError}</p>
            )}

            <section className="mb-6 flex flex-wrap items-center gap-6 rounded-card border border-rule bg-raised px-6 py-5 shadow-card">
                <div className="min-w-[220px] flex-1">
                    <p className="flex items-baseline justify-between text-ui font-extrabold text-ink">
                        Listing strength <span className="text-gold-dark">{strength.percent}%</span>
                    </p>
                    <div className="mt-2 h-2 rounded-full bg-sunken">
                        <div className="h-2 rounded-full bg-gold" style={{ width: `${String(Math.max(3, strength.percent))}%` }} />
                    </div>
                    <p className="mt-2 text-table text-muted">{strength.hint}</p>
                </div>
                {!business.published && (
                    <p className="rounded-sm bg-amber-soft px-4 py-3 text-ui text-amber-ink">
                        Your listing is not published, so buyers cannot see these yet. Publish it from Business profile.
                    </p>
                )}
            </section>

            {adding && canEdit && (
                <ProductForm
                    action={base}
                    onDone={() => {
                        setAdding(false);
                    }}
                />
            )}

            {products.length === 0 && !adding ? (
                <div className="rounded-card border border-dashed border-rule-strong bg-raised px-6 py-14 text-center">
                    <p className="font-display text-display-s text-ink">Nothing listed yet</p>
                    <p className="mx-auto mt-2 max-w-[48ch] text-ui text-muted">
                        Add what you sell with a price and a photograph. Buyers find it on your profile and on the map.
                    </p>
                </div>
            ) : (
                <ul className="grid list-none gap-5 sm:grid-cols-2 xl:grid-cols-3">
                    {products.map((p, i) => (
                        <ProductCard key={p.id} product={p} base={base} canEdit={canEdit} tint={TINTS[i % TINTS.length] ?? ''} maxPhotos={maxPhotos} />
                    ))}
                </ul>
            )}
        </PortalShell>
    );
}

function ProductForm({ action, product, onDone }: { action: string; product?: Product; onDone: () => void }) {
    const form = useForm({
        name: product?.name ?? '',
        unit: product?.unit ?? '',
        price_naira: product?.priceNaira?.toString() ?? '',
        description: product?.description ?? '',
        status: product?.status ?? 'active',
    });

    return (
        <form
            className="mb-6 grid gap-4 rounded-card border-2 border-gold bg-raised p-6 shadow-card sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({ ...d, price_naira: d.price_naira === '' ? null : Number(d.price_naira) }));
                form.post(action, { preserveScroll: true, onSuccess: onDone });
            }}
        >
            <h2 className="font-display text-display-s text-ink sm:col-span-2">{product === undefined ? 'Add a product' : 'Edit product'}</h2>
            <TextField label="Name" value={form.data.name} onChange={(e) => { form.setData('name', e.target.value); }} {...(form.errors.name === undefined ? {} : { error: form.errors.name })} />
            <TextField label="Unit" hint="For example: 50 kg bag, basket, bunch" value={form.data.unit} onChange={(e) => { form.setData('unit', e.target.value); }} />
            <TextField label="Price in naira" type="number" hint="Leave empty for price on request" value={form.data.price_naira} onChange={(e) => { form.setData('price_naira', e.target.value); }} {...(form.errors.price_naira === undefined ? {} : { error: form.errors.price_naira })} />
            <label className="flex flex-col gap-1.5">
                <span className="text-label font-bold tracking-[0.05em] text-muted uppercase">Shown to buyers</span>
                <select
                    value={form.data.status}
                    onChange={(e) => {
                        form.setData('status', e.target.value as 'active' | 'hidden');
                    }}
                    className="h-11 rounded-sm border border-rule-strong bg-raised px-3.5 text-ui text-ink"
                >
                    <option value="active">Yes</option>
                    <option value="hidden">No, keep it hidden</option>
                </select>
            </label>
            <label className="flex flex-col gap-1.5 sm:col-span-2">
                <span className="text-label font-bold tracking-[0.05em] text-muted uppercase">Description</span>
                <textarea
                    rows={3}
                    value={form.data.description}
                    onChange={(e) => {
                        form.setData('description', e.target.value);
                    }}
                    className="rounded-sm border border-rule-strong bg-raised p-3.5 text-ui text-ink focus:border-gold"
                />
            </label>
            <div className="flex gap-3 sm:col-span-2">
                <Button type="submit" variant="primary" size="field" busy={form.processing}>
                    Save
                </Button>
                <Button variant="secondary" size="field" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function ProductCard({ product, base, canEdit, tint, maxPhotos }: { product: Product; base: string; canEdit: boolean; tint: string; maxPhotos: number }) {
    const [editing, setEditing] = useState(false);
    const file = useRef<HTMLInputElement>(null);
    const url = `${base}/${String(product.id)}`;
    const cover = product.photos[0];

    if (editing) {
        return (
            <li className="sm:col-span-2 xl:col-span-3">
                <ProductForm
                    action={url}
                    product={product}
                    onDone={() => {
                        setEditing(false);
                    }}
                />
            </li>
        );
    }

    return (
        <li className="flex flex-col overflow-hidden rounded-card border border-rule bg-raised shadow-card">
            <div className={cx('relative aspect-[16/9]', cover === undefined && tint)}>
                {cover !== undefined ? (
                    <img src={cover.url} alt="" className="h-full w-full object-cover" />
                ) : (
                    <span className="absolute bottom-3 left-4 text-table font-semibold text-muted">No photograph yet</span>
                )}
                {product.status === 'hidden' && (
                    <span className="absolute top-3 left-3 rounded-full bg-raised/95 px-3 py-1 text-table font-bold text-muted">Hidden</span>
                )}
                {product.photos.length > 1 && (
                    <span className="absolute right-3 bottom-3 rounded-full bg-raised/95 px-3 py-1 text-table font-bold text-ink">
                        +{product.photos.length - 1}
                    </span>
                )}
            </div>
            <div className="flex flex-1 flex-col p-5">
                <p className="text-body font-extrabold text-ink">{product.name}</p>
                {product.unit !== null && <p className="text-table text-muted">{product.unit}</p>}
                <div className="mt-auto flex items-end justify-between gap-3 pt-4">
                    <span className="font-display text-display-s text-ink">{naira(product.priceNaira)}</span>
                    {canEdit && (
                        <span className="flex gap-2">
                            <button
                                type="button"
                                onClick={() => {
                                    setEditing(true);
                                }}
                                className="rounded-sm bg-gold-soft px-3.5 py-2 text-table font-extrabold text-gold-dark hover:brightness-95"
                            >
                                Edit
                            </button>
                        </span>
                    )}
                </div>
                {canEdit && (
                    <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-rule pt-4">
                        {product.photos.map((photo) => (
                            <span key={photo.id} className="group relative">
                                <img src={photo.url} alt="" className="size-11 rounded-[8px] object-cover" />
                                <button
                                    type="button"
                                    aria-label="Remove photograph"
                                    onClick={() => {
                                        router.post(`${url}/photos/${String(photo.id)}/withdraw`, {}, { preserveScroll: true });
                                    }}
                                    className="absolute -top-1.5 -right-1.5 hidden size-5 items-center justify-center rounded-full bg-ink text-[0.625rem] text-inverse group-hover:flex"
                                >
                                    ×
                                </button>
                            </span>
                        ))}
                        {product.photos.length < maxPhotos && (
                            <>
                                <button
                                    type="button"
                                    onClick={() => file.current?.click()}
                                    className="flex size-11 items-center justify-center rounded-[8px] border border-dashed border-rule-strong text-muted hover:text-ink"
                                    aria-label="Add a photograph"
                                >
                                    +
                                </button>
                                <input
                                    ref={file}
                                    type="file"
                                    accept="image/jpeg,image/png,image/heic,image/webp"
                                    className="hidden"
                                    onChange={(e) => {
                                        const chosen = e.target.files?.[0];

                                        if (chosen !== undefined) {
                                            router.post(`${url}/photos`, { photo: chosen }, { preserveScroll: true, forceFormData: true });
                                        }
                                    }}
                                />
                            </>
                        )}
                        <button
                            type="button"
                            onClick={() => {
                                router.post(`${url}/withdraw`, {}, { preserveScroll: true });
                            }}
                            className="ml-auto text-table font-bold text-muted hover:text-alert-ink"
                        >
                            Take down
                        </button>
                    </div>
                )}
            </div>
        </li>
    );
}
