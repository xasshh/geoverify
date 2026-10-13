import { Head, Link, usePage } from "@inertiajs/react";
import type { ReactNode } from "react";
import { PeoplePhoto } from "@/components/AuthLayouts";
import { GeoVerifyLockup } from "@/components/GeoVerifyMark";
import {
    Eyebrow,
    SectionHeading,
    SiteFooter,
    SiteHeader,
    type NavItem,
} from "@/components/PublicSite";

const NAV: NavItem[] = [
    {
        label: "Products",
        children: [
            {
                label: "Enumerate",
                href: "/enumerate",
                caption: "Verify any business in Nigeria",
            },
        ],
    },
    { label: "How it works", href: "/how-it-works" },
    { label: "Become an agent", href: "/become-an-agent" },
];

/**
 * How GeoVerify works, for somebody who has never used anything like it.
 *
 * The steps, the closing call and the field network used to sit at the foot of
 * the home page. They live here now, opened by the whole flow drawn once, from
 * a registration to a dated report, with who does each part.
 */
export default function HowGeoVerifyWorks() {
    return (
        <div data-mode="daylight" className="min-h-dvh bg-raised text-ink">
            <Head title="How GeoVerify works" />
            <SiteHeader
                brand={<GeoVerifyLockup size={36} />}
                links={NAV}
                signIn={{ label: "Sign in", href: "/portal/sign-in" }}
            />

            <section className="mx-auto max-w-[1000px] px-4 pt-14 pb-6 text-center sm:px-6">
                <Eyebrow>How it works</Eyebrow>
                <h1 className="mt-2 font-display text-[2.4rem] leading-[1.05] font-extrabold tracking-[-0.02em] sm:text-[3rem]">
                    How GeoVerify works
                </h1>
                <p className="mx-auto mt-4 max-w-[60ch] text-body text-muted">
                    Anybody can put a business name on paper. GeoVerify sends a
                    real person to the address to check that the business is
                    truly there, then keeps a record of what they saw and when.
                    That way, before you pay, partner or lend, you know the
                    business is real.
                </p>
            </section>

            <Flow />
            <Steps />
            <Enumerate />
            <FieldAgents />
            <Closing />
            <SiteFooter />
        </div>
    );
}

/** A line drawing at 24px in the current colour, for the infographic and the cards. */
function Icon({ children }: { children: ReactNode }) {
    return (
        <svg
            width="26"
            height="26"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            {children}
        </svg>
    );
}

const ICONS = {
    building: (
        <Icon>
            <path d="M4 21V5l8-3 8 3v16" />
            <path d="M9 21v-4h6v4M8 8h2M14 8h2M8 12h2M14 12h2M2 21h20" />
        </Icon>
    ),
    search: (
        <Icon>
            <circle cx="11" cy="11" r="6.5" />
            <path d="m20 20-4.2-4.2" />
        </Icon>
    ),
    visit: (
        <Icon>
            <path d="M12 21s-6.5-5.8-6.5-11a6.5 6.5 0 1 1 13 0c0 5.2-6.5 11-6.5 11z" />
            <circle cx="12" cy="10" r="2.3" />
        </Icon>
    ),
    review: (
        <Icon>
            <path d="M12 2.5 4.5 5.5v5.7c0 4.6 3.2 8.6 7.5 10.3 4.3-1.7 7.5-5.7 7.5-10.3V5.5z" />
            <path d="m8.8 12 2.2 2.2 4.4-4.6" />
        </Icon>
    ),
    report: (
        <Icon>
            <path d="M6 2.5h8l4 4V21.5H6z" />
            <path d="M14 2.5v4h4M9 11h6M9 14.5h3" />
            <rect x="12.5" y="16" width="3.5" height="3.5" rx="0.5" />
        </Icon>
    ),
    calendar: (
        <Icon>
            <rect x="3.5" y="5" width="17" height="15.5" rx="2" />
            <path d="M3.5 10h17M8 3v4M16 3v4" />
            <path d="m9.5 15 1.8 1.8 3.4-3.6" />
        </Icon>
    ),
    offline: (
        <Icon>
            <path d="M2.5 8.5a14 14 0 0 1 5-2.9M12 5.2a14 14 0 0 1 9.5 3.3M5.5 12a9 9 0 0 1 4-2.2M15 10.2a9 9 0 0 1 3.5 1.8M8.8 15.3a4.5 4.5 0 0 1 6.4 0" />
            <circle cx="12" cy="19" r="0.6" fill="currentColor" />
            <path d="m3 3 18 18" />
        </Icon>
    ),
    training: (
        <Icon>
            <path d="m2.5 9 9.5-4.5L21.5 9 12 13.5z" />
            <path d="M6.5 11v4.5c1.5 1.5 3.5 2.2 5.5 2.2s4-.7 5.5-2.2V11M21.5 9v5" />
        </Icon>
    ),
    home: (
        <Icon>
            <path d="M3.5 11 12 4l8.5 7" />
            <path d="M5.5 9.5V20h13V9.5" />
            <path d="M12 17.5s-2.6-2.3-2.6-4.3a2.6 2.6 0 0 1 5.2 0c0 2-2.6 4.3-2.6 4.3z" />
        </Icon>
    ),
};

/**
 * The whole flow, drawn once: who does what, from a registration to a report
 * anyone can confirm. A row of five on a wide screen, a line down the side on
 * a phone.
 */
function Flow() {
    const portalOpen = usePage().props.surfaces.portal;
    const steps: Array<{
        icon: ReactNode;
        who: string;
        title: string;
        line: string;
    }> = [
        {
            icon: ICONS.building,
            who: "The business",
            title: "Registers",
            line: "Gets its RC or BN number from CAC.",
        },
        {
            icon: ICONS.search,
            who: "You",
            title: "Look it up",
            line: "Enter that number on Enumerate.",
        },
        {
            icon: ICONS.visit,
            who: "A field agent",
            title: "Visits",
            line: "Goes to the address, takes photos and a GPS position.",
        },
        {
            icon: ICONS.review,
            who: "GeoVerify",
            title: "Reviews",
            line: "A supervisor checks the agent's work.",
        },
        {
            icon: ICONS.report,
            who: "You",
            title: "Get a report",
            line: "Dated, with a QR code anyone can scan.",
        },
    ];
    const doors: Array<{ who: string; label: string; href: string | null }> = [
        {
            who: "Checking a business?",
            label: "Find a location",
            href: "/enumerate",
        },
        {
            who: "Own a business?",
            label: portalOpen ? "EasyBuzi" : "EasyBuzi opens soon",
            href: portalOpen ? "/portal/register" : null,
        },
        {
            who: "Want to work with us?",
            label: "Become an agent",
            href: "/become-an-agent",
        },
    ];

    return (
        <section className="mx-auto max-w-[1200px] px-4 py-10 sm:px-6">
            <div className="rounded-[24px] bg-sunken px-5 py-10 sm:px-10">
                <h2 className="text-center font-display text-[1.6rem] font-extrabold">
                    From paper to proof, in five steps
                </h2>
                <ol className="relative mt-10 grid list-none gap-8 p-0 md:grid-cols-5 md:gap-4">
                    {/* The thread joining the steps: across on a wide screen, down on a phone. */}
                    <span
                        aria-hidden="true"
                        className="absolute top-0 bottom-0 left-7 w-0.5 bg-gold-dark/25 md:top-7 md:right-[10%] md:bottom-auto md:left-[10%] md:h-0.5 md:w-auto"
                    />
                    {steps.map((step, i) => (
                        <li
                            key={step.title}
                            className="relative flex gap-4 md:flex-col md:items-center md:text-center"
                        >
                            <span className="relative flex size-14 shrink-0 items-center justify-center rounded-full border-2 border-gold-dark bg-raised text-gold-dark shadow-card">
                                {step.icon}
                                <span className="absolute -top-1.5 -right-1.5 flex size-6 items-center justify-center rounded-full bg-gold-dark text-[0.7rem] font-extrabold text-on-accent">
                                    {i + 1}
                                </span>
                            </span>
                            <span className="md:mt-3">
                                <span className="block text-[0.7rem] font-extrabold tracking-[0.08em] text-gold-dark uppercase">
                                    {step.who}
                                </span>
                                <span className="mt-0.5 block font-display text-[1.15rem] font-extrabold text-ink">
                                    {step.title}
                                </span>
                                <span className="mt-1 block text-ui text-muted">
                                    {step.line}
                                </span>
                            </span>
                        </li>
                    ))}
                </ol>
                <div className="mt-10 flex flex-wrap justify-center gap-3 border-t border-rule pt-8">
                    {doors.map((door) =>
                        door.href === null ? (
                            <span
                                key={door.who}
                                className="flex items-center gap-2 rounded-full bg-raised px-4 py-2 text-ui text-muted"
                            >
                                {door.who}{" "}
                                <strong className="font-extrabold">
                                    {door.label}
                                </strong>
                            </span>
                        ) : (
                            <Link
                                key={door.who}
                                href={door.href}
                                className="flex items-center gap-2 rounded-full bg-raised px-4 py-2 text-ui text-muted shadow-card hover:text-ink"
                            >
                                {door.who}{" "}
                                <strong className="font-extrabold text-gold-dark">
                                    {door.label}
                                </strong>
                            </Link>
                        ),
                    )}
                </div>
            </div>
        </section>
    );
}

function Steps() {
    return (
        <section id="steps" className="scroll-mt-20 bg-raised">
            <div className="mx-auto max-w-[1200px] px-4 py-16 sm:px-6">
                <SectionHeading
                    align="center"
                    title={
                        <span className="text-gold-dark">
                            From application to a verified point on the map
                        </span>
                    }
                    intro="Three steps take a business from a written application to a confirmed place on the map that buyers and investors can find."
                />
                <div className="mt-10 grid gap-4 md:grid-cols-3">
                    {[
                        [
                            "01",
                            "Register",
                            "Create an account and tell us about your business, including the address where people can find it.",
                        ],
                        [
                            "02",
                            "Verify",
                            "A trained field agent visits the address, confirms the business is there, and records its exact location on the map.",
                        ],
                        [
                            "03",
                            "Get listed",
                            "Your business appears as a verified point on the national map, where buyers and investors can find it.",
                        ],
                    ].map(([n, title, body]) => (
                        <div
                            key={n}
                            className="rounded-card border border-gold-dark/30 p-6"
                        >
                            <p className="font-display text-[3rem] leading-none font-extrabold text-gold-dark">
                                {n}
                            </p>
                            <p className="mt-4 font-display text-[1.4rem] font-extrabold text-ink">
                                {title}
                            </p>
                            <p className="mt-2 text-ui text-muted">{body}</p>
                        </div>
                    ))}
                </div>
                <PeoplePhoto
                    name="people-agent-visit"
                    alt="A field agent in a teal vest talking with a shop owner outside his shop"
                    caption="Step 2 in real life: an agent at the door, confirming the business is there"
                    focus="50% 12%"
                    className="mt-8 h-[480px] w-full"
                />
            </div>
        </section>
    );
}

/** Checking somebody else's business, the one product open today. */
function Enumerate() {
    return (
        <section className="bg-sunken">
            <div className="mx-auto max-w-[1200px] px-4 py-16 sm:px-6">
                <SectionHeading
                    eyebrow="Checking a business"
                    title="How a check with Enumerate works"
                    intro="Enumerate is the part of GeoVerify you use to check any business in Nigeria. You need its CAC number: the RC or BN number on its certificate, invoice or letterhead."
                />
                <ol className="mt-8 grid list-none gap-4 p-0 md:grid-cols-4">
                    {[
                        ["Sign up", "A free account with your email."],
                        [
                            "Choose how to check",
                            "Search by the business's CAC number.",
                        ],
                        [
                            "Deep dive",
                            "Records only, an agent visit, or daily visits.",
                        ],
                        [
                            "Get your report",
                            "Download it. Its QR code proves it is genuine.",
                        ],
                    ].map(([title, body], i) => (
                        <li key={title} className="rounded-card bg-raised p-5">
                            <span className="flex size-9 items-center justify-center rounded-full bg-gold-soft text-ui font-extrabold text-gold-dark">
                                {i + 1}
                            </span>
                            <p className="mt-3 text-body font-extrabold text-ink">
                                {title}
                            </p>
                            <p className="mt-1 text-ui text-muted">{body}</p>
                        </li>
                    ))}
                </ol>
                <div className="mt-8 flex flex-wrap items-center gap-4">
                    <Link
                        href="/enumerate"
                        className="rounded-full bg-gold-dark px-5 py-2.5 text-ui font-extrabold text-on-accent hover:bg-gold"
                    >
                        Verify a business
                    </Link>
                </div>
            </div>
        </section>
    );
}

function FieldAgents() {
    return (
        <section id="field-network" className="scroll-mt-20 bg-sunken">
            <div className="mx-auto grid max-w-[1200px] items-center gap-10 px-4 py-16 sm:px-6 lg:grid-cols-2">
                <div>
                    <Eyebrow>Field network</Eyebrow>
                    <h2 className="mt-2 font-display text-[2rem] leading-[1.1] font-extrabold tracking-[-0.02em] sm:text-[2.4rem]">
                        Become a GeoVerify field officer
                    </h2>
                    <p className="mt-4 max-w-[54ch] text-body text-muted">
                        Join the people across Nigeria who confirm that
                        businesses are where they say they are. Field officers
                        are the reason a GeoVerify listing means something:
                        every pin on the map was visited by someone real.
                    </p>
                    <Link
                        href="/become-an-agent"
                        className="mt-6 inline-flex rounded-full bg-gold-dark px-5 py-2.5 text-ui font-extrabold text-on-accent hover:bg-gold"
                    >
                        Become an agent
                    </Link>
                </div>
                <ul className="grid gap-3 sm:grid-cols-2">
                    {(
                        [
                            [
                                ICONS.calendar,
                                "Clear daily work",
                                "Each day you get a small area on the map, with the buildings to visit already marked.",
                            ],
                            [
                                ICONS.offline,
                                "Works without data",
                                "The app keeps your work on the phone until you have signal again.",
                            ],
                            [
                                ICONS.training,
                                "Trained and supported",
                                "We train you first, and a supervisor checks your work and messages you in the app.",
                            ],
                            [
                                ICONS.home,
                                "Near where you live",
                                "You work in your own area, a day at a time.",
                            ],
                        ] as Array<[ReactNode, string, string]>
                    ).map(([icon, title, body]) => (
                        <li
                            key={title}
                            className="flex gap-3 rounded-card border border-rule bg-raised p-4"
                        >
                            <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-gold-soft text-gold-dark">
                                {icon}
                            </span>
                            <span>
                                <span className="block text-ui font-extrabold text-ink">
                                    {title}
                                </span>
                                <span className="mt-1 block text-label text-muted">
                                    {body}
                                </span>
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}

function Closing() {
    const portalOpen = usePage().props.surfaces.portal;
    return (
        <section className="bg-raised px-4 py-16 sm:px-6">
            <div className="mx-auto flex max-w-[1200px] flex-wrap items-center justify-between gap-6 rounded-[24px] bg-[#0E4A44] px-6 py-10 text-inverse sm:px-10">
                <div className="max-w-[56ch]">
                    <h2 className="font-display text-[1.8rem] leading-tight font-extrabold tracking-[-0.02em]">
                        Be part of Nigeria's first geo-verified business
                        registry.
                    </h2>
                    <p className="mt-2 text-ui text-inverse/80">
                        Check a business before you pay, or join us as a field
                        agent.
                    </p>
                </div>
                <div className="flex flex-wrap gap-3">
                    <Link
                        href={portalOpen ? "/portal/register" : "/enumerate"}
                        className="rounded-full bg-logo px-5 py-2.5 text-ui font-extrabold text-ink hover:bg-white"
                    >
                        {portalOpen ? "Get started" : "Find a location"}
                    </Link>
                    <Link
                        href="/become-an-agent"
                        className="rounded-full bg-white px-5 py-2.5 text-ui font-extrabold text-ink hover:bg-white/90"
                    >
                        Become an agent
                    </Link>
                </div>
            </div>
        </section>
    );
}
