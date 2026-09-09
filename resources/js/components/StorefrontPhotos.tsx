import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { buttonClass } from '@/lib/button';

export interface StorefrontPhoto {
    id: number;
    url: string;
}

/**
 * The photographs a business shows of itself.
 *
 * The one place in the portal where a party puts something new in front of
 * strangers on its own authority, so the page says so plainly rather than
 * letting an upload control imply a private file store. What is not said here
 * is a warning about faces or documents: the copy asks for the shopfront, and
 * a business photographing its own counter is not a privacy problem the way an
 * officer photographing an interior is.
 *
 * Withdrawing is worded as taking it down rather than deleting it, because that
 * is what happens. The row stays.
 */
export function StorefrontPhotos({
    enterpriseId,
    photos,
    limit,
    published,
    error,
}: {
    enterpriseId: number;
    photos: StorefrontPhoto[];
    limit: number;
    published: boolean;
    error?: string;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);

    const full = photos.length >= limit;

    return (
        <section className="rounded-sm border border-rule" aria-labelledby="photos">
            <div className="flex flex-wrap items-baseline justify-between gap-3 border-b border-rule px-5 py-4">
                <h2
                    id="photos"
                    className="text-label font-semibold tracking-[0.12em] text-muted uppercase"
                >
                    Photographs of your business
                </h2>
                <span className="numeric-mono text-table text-faint">
                    {photos.length} of {limit}
                </span>
            </div>

            <div className="px-5 py-4">
                <p className="text-ui text-muted">
                    {published
                        ? 'These are shown to anybody who finds your listing in the directory.'
                        : 'These appear in the public directory once you publish this listing. Until then only you can see them.'}
                </p>

                {error !== undefined && (
                    <p className="mt-3 border-l-2 border-alert bg-raised px-3 py-2 text-ui text-alert">
                        {error}
                    </p>
                )}

                {photos.length > 0 && (
                    <ul className="mt-4 grid list-none grid-cols-2 gap-3 sm:grid-cols-3">
                        {photos.map((photo) => (
                            <li key={photo.id} className="flex flex-col gap-1.5">
                                <img
                                    src={photo.url}
                                    alt=""
                                    className="aspect-4/3 w-full rounded-sm border border-rule object-cover"
                                />
                                <button
                                    type="button"
                                    onClick={() => {
                                        router.post(
                                            `/portal/businesses/${String(enterpriseId)}/photos/${String(photo.id)}/withdraw`,
                                            {},
                                            { preserveScroll: true },
                                        );
                                    }}
                                    className="min-h-touch text-table text-muted underline underline-offset-4 hover:text-ink"
                                >
                                    Take this one down
                                </button>
                            </li>
                        ))}
                    </ul>
                )}

                <input
                    ref={input}
                    type="file"
                    accept="image/jpeg,image/png,image/heic,image/webp"
                    hidden
                    onChange={(event) => {
                        const file = event.target.files?.[0];

                        if (file === undefined) {
                            return;
                        }

                        setBusy(true);
                        router.post(
                            `/portal/businesses/${String(enterpriseId)}/photos`,
                            { photo: file },
                            {
                                preserveScroll: true,
                                forceFormData: true,
                                onFinish: () => {
                                    setBusy(false);

                                    if (input.current !== null) {
                                        input.current.value = '';
                                    }
                                },
                            },
                        );
                    }}
                />

                <button
                    type="button"
                    disabled={full || busy}
                    onClick={() => {
                        input.current?.click();
                    }}
                    className={`${buttonClass('secondary', 'field-compact')} mt-4 disabled:cursor-not-allowed disabled:opacity-45`}
                >
                    {busy ? 'Adding' : full ? `That is all ${String(limit)}` : 'Add a photograph'}
                </button>

                <p className="mt-2 text-label text-faint">
                    A photograph of the front, the signage or what you sell. Up to 12 MB.
                </p>
            </div>
        </section>
    );
}
