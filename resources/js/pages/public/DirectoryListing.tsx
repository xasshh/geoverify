import { Head, Link, router, usePage } from "@inertiajs/react";
import { useState } from "react";
import {
    DepthMark,
    DirectoryChrome,
    EntryCard,
    type DirectoryEntry,
} from "@/components/DirectoryChrome";
import { addToCart, useCart } from "@/lib/cart";

interface Listing {
    id: number;
    depth: "reduced" | "claimed" | "verified";
    tradingName: string;
    sector: string | null;
    structureType: string;
    ward: string | null;
    lga: string | null;
    tier: string;
    verified: boolean;
    openingHours: string | null;
    photos: { url: string }[];
}

/**
 * One business, to a stranger.
 *
 * The page says what is known and then says, in the same weight, what is not.
 * A directory entry that lists four facts and stays quiet about the fifth
 * invites the reader to assume the fifth: that somebody checked. Most of these
 * businesses have not been checked, and the page's first job is to be honest
 * about that before it is useful about anything else.
 *
 * There is no phone number here, no email, no photograph and no coordinate, at
 * any depth. Those are in the register and they stay there.
 */
interface ListedProduct {
    id: number;
    name: string;
    unit: string | null;
    priceNaira: number | null;
    description: string | null;
    photos: { url: string }[];
}

export default function DirectoryListing({
    listing,
    products,
    similar,
}: {
    listing: Listing;
    products: ListedProduct[];
    similar: DirectoryEntry[];
}) {
    const flash = usePage().props.flash.status;
    const [tab, setTab] = useState<"products" | "about">(
        products.length > 0 ? "products" : "about",
    );
    const [asking, setAsking] = useState(false);
    const cart = useCart(listing.id);
    const cartCount =
        cart?.lines.reduce((n, line) => n + line.quantity, 0) ?? 0;
    const cartTotal =
        cart?.lines.reduce((n, line) => n + line.quantity * line.priceNaira, 0) ??
        0;
    const inCart = (id: number) =>
        cart?.lines.find((line) => line.id === id)?.quantity ?? 0;
    const buyable = products.some(
        (p) => p.priceNaira !== null && p.priceNaira > 0,
    );
    const [reason, setReason] = useState("");

    const place = [listing.ward, listing.lga].filter(Boolean).join(", ");

    return (
        <DirectoryChrome width="wide">
            <Head title={listing.tradingName} />

            <main className="mx-auto max-w-6xl px-5 py-8">
                <Link
                    href="/directory"
                    className="text-table text-muted underline underline-offset-4 hover:text-ink"
                >
                    Directory
                </Link>

                {flash !== null && flash !== "" && (
                    <p className="rounded-sm bg-green-soft mt-4 px-4 py-3 text-ui text-ink">
                        {flash}
                    </p>
                )}

                {listing.photos.length > 0 && (
                    <section
                        className="mt-5"
                        aria-label="Photographs of this business"
                    >
                        <ul className="grid list-none gap-3 sm:grid-cols-[2fr_1fr_1fr]">
                            {listing.photos.slice(0, 3).map((photo, index) => (
                                <li key={photo.url} className="relative">
                                    <img
                                        src={photo.url}
                                        alt=""
                                        className="aspect-4/3 h-full w-full rounded-card border border-rule object-cover"
                                    />
                                    {index === 0 && (
                                        <span className="absolute top-3 left-3 rounded-full bg-raised/95 px-3 py-1 text-label font-bold tracking-normal text-ink shadow-card">
                                            Published by the business
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                        <p className="mt-2 text-table text-faint">
                            Photographs published by the business itself, not by
                            this register.
                        </p>
                    </section>
                )}

                <div className="mt-6 flex flex-wrap items-center gap-5">
                    <span
                        aria-hidden="true"
                        className="flex size-[72px] shrink-0 items-center justify-center rounded-card bg-gold font-display text-display-m text-on-accent"
                    >
                        {initialsOf(listing.tradingName)}
                    </span>
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="font-display text-display-xl text-ink">
                                {listing.tradingName}
                            </h1>
                            <DepthMark depth={listing.depth} />
                        </div>
                        <p className="mt-1.5 text-body text-muted">
                            {listing.sector ?? listing.structureType}
                            {place !== "" && ` · ${place}`}
                            {listing.openingHours !== null && (
                                <>
                                    {" · "}
                                    <span className="font-semibold text-gold">
                                        {listing.openingHours}
                                    </span>
                                </>
                            )}
                        </p>
                    </div>
                </div>

                <div className="mt-8 grid gap-6 lg:grid-cols-[minmax(0,1fr)_400px] lg:items-start">
                    <div>
                        <div
                            role="tablist"
                            aria-label="About this business"
                            className="mb-5 flex gap-6 border-b border-rule"
                        >
                            {products.length > 0 && (
                                <button
                                    type="button"
                                    role="tab"
                                    aria-selected={tab === "products"}
                                    onClick={() => {
                                        setTab("products");
                                    }}
                                    className={`-mb-px border-b-[3px] px-1 pb-3 text-body font-bold ${tab === "products" ? "border-gold text-gold-dark" : "border-transparent text-muted hover:text-ink"}`}
                                >
                                    Products ({products.length})
                                </button>
                            )}
                            <button
                                type="button"
                                role="tab"
                                aria-selected={tab === "about"}
                                onClick={() => {
                                    setTab("about");
                                }}
                                className={`-mb-px border-b-[3px] px-1 pb-3 text-body font-bold ${tab === "about" ? "border-gold text-gold-dark" : "border-transparent text-muted hover:text-ink"}`}
                            >
                                About
                            </button>
                        </div>

                        {tab === "products" ? (
                            <ul className="grid list-none gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                {products.map((p) => (
                                    <li
                                        key={p.id}
                                        className="flex flex-col overflow-hidden rounded-card border border-rule bg-raised shadow-card"
                                    >
                                        <div className="aspect-[16/10] bg-[#EEF1E8]">
                                            {p.photos[0] !== undefined && (
                                                <img
                                                    src={p.photos[0].url}
                                                    alt=""
                                                    className="h-full w-full object-cover"
                                                />
                                            )}
                                        </div>
                                        <div className="flex flex-1 flex-col p-4">
                                            <p className="text-body font-extrabold text-ink">
                                                {p.name}
                                            </p>
                                            {p.unit !== null && (
                                                <p className="text-table text-muted">
                                                    {p.unit}
                                                </p>
                                            )}
                                            <div className="mt-auto flex items-end justify-between gap-3 pt-3">
                                                <p className="font-display text-display-s text-ink">
                                                    {p.priceNaira === null
                                                        ? "Price on request"
                                                        : `₦${p.priceNaira.toLocaleString("en-NG")}`}
                                                </p>
                                                {p.priceNaira !== null &&
                                                    p.priceNaira > 0 && (
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                addToCart(
                                                                    {
                                                                        id: listing.id,
                                                                        name: listing.tradingName,
                                                                    },
                                                                    {
                                                                        id: p.id,
                                                                        name: p.name,
                                                                        unit: p.unit,
                                                                        priceNaira:
                                                                            p.priceNaira ??
                                                                            0,
                                                                    },
                                                                );
                                                            }}
                                                            className="inline-flex min-h-touch shrink-0 items-center rounded-sm bg-gold px-4 text-ui font-extrabold text-on-accent hover:bg-gold-dark"
                                                        >
                                                            {inCart(p.id) > 0
                                                                ? `In cart · ${String(inCart(p.id))}`
                                                                : "Add to cart"}
                                                        </button>
                                                    )}
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <>
                        <dl className="grid gap-x-8 rounded-card border border-rule bg-raised px-6 py-2 sm:grid-cols-2">
                            <Fact term="What it does">
                                {listing.sector ?? "Not recorded"}
                            </Fact>
                            <Fact term="Premises">{listing.structureType}</Fact>
                            <Fact term="Ward">
                                {listing.ward ?? "Not resolved"}
                            </Fact>
                            <Fact term="Local government">
                                {listing.lga ?? "Not resolved"}
                            </Fact>
                            {listing.openingHours !== null && (
                                <Fact term="Opening hours">
                                    {listing.openingHours}
                                </Fact>
                            )}
                        </dl>

                        <section
                            className="mt-6 px-1"
                            aria-labelledby="not-shown"
                        >
                            <h2
                                id="not-shown"
                                className="text-label font-semibold tracking-[0.05em] text-muted uppercase"
                            >
                                What this page does not show
                            </h2>
                            <p className="mt-2 max-w-[62ch] text-ui text-muted">
                                Not the phone number or email recorded at the
                                door, not the exact coordinate, and none of the
                                photographs an officer took. Those belong to the
                                business, and being surveyed is not consent to
                                publish them.
                                {listing.photos.length > 0 &&
                                    " The photographs above are the ones the business published itself."}
                            </p>
                        </section>
                            </>
                        )}
                    </div>

                    <div className="flex flex-col gap-5">
                        {cartCount > 0 && (
                            <Link
                                href={`/portal/checkout/${String(listing.id)}`}
                                className="flex min-h-[56px] items-center justify-between rounded-card bg-ink px-5 text-ui font-extrabold text-inverse hover:bg-graphite"
                            >
                                <span>
                                    Checkout · {cartCount}{" "}
                                    {cartCount === 1 ? "item" : "items"}
                                </span>
                                <span>
                                    ₦{cartTotal.toLocaleString("en-NG")}
                                </span>
                            </Link>
                        )}
                        <VerificationRecord listing={listing} />
                        {buyable && <MoneyProtected />}
                        <section
                            className="rounded-card border border-rule bg-raised px-6 py-6"
                            aria-labelledby="owner"
                        >
                            <h2
                                id="owner"
                                className="font-display text-display-s text-ink"
                            >
                                Is this your business?
                            </h2>
                            <p className="mt-2 max-w-[62ch] text-ui text-muted">
                                Claim it and you decide what appears here. You
                                can also ask for it to be taken down, and you do
                                not have to claim it first to do that.
                            </p>
                            <div className="mt-4 flex flex-wrap items-center gap-4">
                                <Link
                                    href="/portal/sign-in"
                                    className="inline-flex min-h-touch items-center rounded-sm border border-transparent bg-gold px-5 text-ui font-semibold text-on-accent"
                                >
                                    Claim this listing
                                </Link>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setAsking((open) => !open);
                                    }}
                                    className="min-h-touch text-ui text-muted underline underline-offset-4 hover:text-ink"
                                >
                                    Ask for it to be removed
                                </button>
                            </div>

                            {asking && (
                                <form
                                    className="mt-5 border-t border-rule pt-5"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        router.post(
                                            `/directory/${String(listing.id)}/remove`,
                                            { reason },
                                        );
                                    }}
                                >
                                    <label
                                        htmlFor="reason"
                                        className="text-label font-semibold tracking-[0.05em] text-muted uppercase"
                                    >
                                        Why, if you want to say
                                    </label>
                                    <textarea
                                        id="reason"
                                        value={reason}
                                        onChange={(event) => {
                                            setReason(event.target.value);
                                        }}
                                        rows={3}
                                        className="mt-2 w-full rounded-sm border border-rule-strong bg-raised p-3 text-ui text-ink focus:border-gold focus:outline-2 focus:outline-gold"
                                    />
                                    <p className="mt-2 text-table text-faint">
                                        This takes the listing out of the
                                        directory. It does not delete the
                                        business from the register, and the
                                        owner can publish it again by claiming
                                        it.
                                    </p>
                                    <button
                                        type="submit"
                                        className="mt-3 min-h-touch rounded-sm border border-rule-strong px-5 text-ui font-medium text-ink hover:border-ink"
                                    >
                                        Take it down
                                    </button>
                                </form>
                            )}
                        </section>
                    </div>
                </div>

                {similar.length > 0 && (
                    <section
                        className="mt-10 border-t border-rule pt-7"
                        aria-labelledby="similar"
                    >
                        <h2
                            id="similar"
                            className="font-display text-display-s text-ink"
                        >
                            Others like this nearby
                        </h2>
                        <p className="mt-1 text-ui text-muted">
                            {listing.sector ?? "The same trade"} in{" "}
                            {listing.lga ?? "this area"}. Verified businesses
                            are shown first.
                        </p>
                        <ul className="mt-4 grid list-none gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {similar.map((entry) => (
                                <li key={entry.id}>
                                    <EntryCard entry={entry} />
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </main>
        </DirectoryChrome>
    );
}

/**
 * "Your money is protected", from the mockup's profile, in words we are allowed
 * to use: the money is held, and released when the buyer says so.
 */
function MoneyProtected() {
    return (
        <section
            className="rounded-card border border-rule bg-raised px-6 py-6"
            aria-labelledby="protected"
        >
            <h2
                id="protected"
                className="font-display text-display-s text-ink"
            >
                Your money is protected
            </h2>
            <p className="mt-2 text-ui text-muted">
                Pay on GeoVerify and the money is held until you confirm
                delivery. The business is paid only after you say you received
                your order, and if something is wrong you tell us before
                anything is released.
            </p>
            <dl className="mt-4 grid grid-cols-2 gap-3">
                <div className="rounded-sm border border-rule px-3 py-3">
                    <dt className="text-ui font-bold text-ink">
                        Product inspection
                    </dt>
                    <dd className="mt-1 text-table text-muted">
                        An agent checks goods before dispatch. Coming soon.
                    </dd>
                </div>
                <div className="rounded-sm border border-rule px-3 py-3">
                    <dt className="text-ui font-bold text-ink">Site visit</dt>
                    <dd className="mt-1 text-table text-muted">
                        An agent visits with you, or for you. Coming soon.
                    </dd>
                </div>
            </dl>
        </section>
    );
}

function Fact({ term, children }: { term: string; children: React.ReactNode }) {
    return (
        <div className="flex flex-col gap-0.5 border-b border-rule py-3">
            <dt className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                {term}
            </dt>
            <dd className="text-ui text-ink">{children}</dd>
        </div>
    );
}

function initialsOf(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase() ?? "")
        .join("");
}

/**
 * The guide's verification record: what has been established, one line each,
 * with the ones that have not been established shown as plainly as the ones
 * that have. Built only from the depth and the ward already on this page, so
 * it can say nothing the projection did not already say.
 */
function VerificationRecord({ listing }: { listing: Listing }) {
    const rows: { done: boolean; title: string; detail: string }[] = [
        {
            done: true,
            title: "On the register",
            detail:
                listing.depth === "reduced"
                    ? "Recorded by an officer surveying the area"
                    : "Recorded by an officer or added by its owner",
        },
        ...(listing.depth === "reduced"
            ? [
                  {
                      done: true,
                      title: "Name on the premises",
                      detail: "The officer saw this name on its signage",
                  },
              ]
            : []),
        {
            done: listing.ward !== null,
            title: "Place resolved",
            detail:
                listing.ward !== null
                    ? `Ward worked out from its position: ${listing.ward}`
                    : "Its position did not fall inside a known ward",
        },
        {
            done: listing.depth !== "reduced",
            title: "Owner proved control",
            detail:
                listing.depth !== "reduced"
                    ? "Somebody proved this business is theirs and chose to publish it"
                    : "Not claimed by its owner",
        },
        {
            done: listing.depth === "verified",
            title: "Officer attended",
            detail:
                listing.depth === "verified"
                    ? "An officer went to the address and recorded what they found"
                    : "Nobody from this register has visited to check",
        },
    ];

    return (
        <section
            className="rounded-card border border-rule bg-raised px-6 py-6"
            aria-labelledby="record"
        >
            <h2 id="record" className="font-display text-display-s text-ink">
                Verification record
            </h2>
            <ul className="mt-4 flex list-none flex-col gap-4">
                {rows.map((row) => (
                    <li key={row.title} className="flex gap-3">
                        <span
                            aria-hidden="true"
                            className={`mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full ${
                                row.done
                                    ? "bg-gold-soft text-gold"
                                    : "bg-sunken text-faint"
                            }`}
                        >
                            {row.done ? (
                                <svg
                                    width="14"
                                    height="14"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="3"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                >
                                    <path d="M5 12.5l4.5 4.5L19 7.5" />
                                </svg>
                            ) : (
                                <svg
                                    width="12"
                                    height="12"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="3"
                                    strokeLinecap="round"
                                >
                                    <path d="M6 12h12" />
                                </svg>
                            )}
                        </span>
                        <span className="flex flex-col">
                            <span
                                className={`text-ui font-bold ${row.done ? "text-ink" : "text-muted"}`}
                            >
                                {row.title}
                                <span className="sr-only">
                                    {row.done
                                        ? ", established"
                                        : ", not established"}
                                </span>
                            </span>
                            <span className="text-table text-faint">
                                {row.detail}
                            </span>
                        </span>
                    </li>
                ))}
            </ul>
        </section>
    );
}
