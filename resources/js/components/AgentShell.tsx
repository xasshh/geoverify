import { Head, Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { GeoVerifyLockup } from '@/components/GeoVerifyMark';
import { cx } from '@/lib/cx';

export interface AgentFrame {
    name: string;
    email: string | null;
    proved: boolean;
}

/** The field portal's sections, drawn as FieldShell draws them. */
const LOCKED: { label: string; tab?: string; icon: string }[] = [
    { label: 'Today', tab: 'Today', icon: 'M3 10.5 12 3l9 7.5M5.5 9v11h13V9' },
    { label: 'My map', tab: 'Map', icon: 'M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11zM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z' },
    { label: 'New capture', icon: 'M12 5v14M5 12h14' },
    { label: 'My records', tab: 'Records', icon: 'M6 3h9l3 3v15H6zM9 9h6M9 13h6M9 17h4' },
    { label: 'Supervisor inbox', tab: 'Inbox', icon: 'M4 5h16v11H9l-5 4z' },
    { label: 'Campaign brief', icon: 'M6 3h9l3 3v15H6zM9 12h6M9 16h6' },
    { label: 'Sync & device', icon: 'M4 12a8 8 0 0 1 14-5.3M20 12a8 8 0 0 1-14 5.3M18 3v4h-4M6 21v-4h4' },
];
const APPLICATION_ICON = 'M7 3h7l4 4v14H7zM14 3v4h4M9.5 13l2 2 3.5-4';
const LOCK_ICON = 'M7 11V8a5 5 0 0 1 10 0v3M5.5 11h13v10h-13z';

function Glyph({ d, className }: { d: string; className?: string }) {
    return (
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className={cx('shrink-0', className)}>
            <path d={d} />
        </svg>
    );
}

const LOCKED_NOTE = 'Unlocks once your application is approved';

/**
 * The field portal, as an applicant sees it: the same sections an officer
 * works in, locked, beside the one thing they can do now, their application.
 * Nothing here links into /field: those pages are on the staff guard and an
 * applicant has no staff account until they are approved.
 */
export function AgentShell({ frame, title, children }: { frame: AgentFrame; title: string; children: ReactNode }) {
    const flash = usePage().props.flash.status;
    const firstName = frame.name.split(/\s+/)[0] ?? frame.name;

    const signOut = () => {
        router.post('/agent/sign-out');
    };

    return (
        <div data-mode="daylight" className="min-h-dvh bg-surface pb-20 text-ink lg:grid lg:grid-cols-[260px_minmax(0,1fr)] lg:pb-0">
            <Head title={title} />

            <aside className="hidden border-r border-rule bg-raised lg:sticky lg:top-0 lg:flex lg:h-dvh lg:flex-col">
                <Link href="/agent/apply" className="flex items-center px-6 pt-6 pb-6">
                    <GeoVerifyLockup size={36} caption="Field · Applicant" />
                </Link>
                <nav className="flex flex-col gap-1 px-4" aria-label="Field portal">
                    <Link
                        href="/agent/apply"
                        aria-current="page"
                        className="flex min-h-[46px] items-center gap-3 rounded-sm bg-gold-soft px-3.5 text-ui font-bold text-gold-dark"
                    >
                        <Glyph d={APPLICATION_ICON} />
                        My application
                    </Link>
                    <p className="mt-4 mb-1 px-3.5 text-[0.7rem] font-extrabold tracking-[0.08em] text-muted uppercase">After approval</p>
                    {LOCKED.map((item) => (
                        <span
                            key={item.label}
                            aria-disabled="true"
                            title={LOCKED_NOTE}
                            className="flex min-h-[42px] cursor-not-allowed items-center gap-3 rounded-sm px-3.5 text-ui font-semibold text-faint"
                        >
                            <Glyph d={item.icon} />
                            {item.label}
                            <Glyph d={LOCK_ICON} className="ml-auto size-4" />
                        </span>
                    ))}
                </nav>

                <div className="mt-auto flex flex-col gap-3 px-4 pb-5">
                    <div className="rounded-card bg-sunken px-4 py-3.5 text-table">
                        <p className="font-bold text-ink">{frame.name}</p>
                        <p className="truncate text-muted">{frame.email}</p>
                        <button type="button" onClick={signOut} className="mt-2 font-bold text-gold-dark underline underline-offset-2 hover:text-ink">
                            Sign out
                        </button>
                    </div>
                </div>
            </aside>

            <div className="min-w-0">
                <header className="flex items-center justify-between gap-3 border-b border-rule bg-raised px-4 py-3 lg:hidden">
                    <GeoVerifyLockup size={30} caption="Field · Applicant" />
                    <button type="button" onClick={signOut} className="text-ui font-bold text-gold-dark">
                        Sign out
                    </button>
                </header>

                <div className="border-b border-rule bg-[#0F1A17] px-4 py-6 text-inverse sm:px-8">
                    <p className="text-label font-extrabold tracking-[0.08em] text-logo uppercase">Field portal</p>
                    <h1 className="mt-1 font-display text-[1.6rem] leading-tight font-extrabold sm:text-[1.9rem]">Welcome, {firstName}</h1>
                    <p className="mt-1 max-w-[60ch] text-ui text-inverse/75">
                        Your work in the field app unlocks once we approve your application. Start with the video, then fill in the four parts.
                    </p>
                </div>

                <main className="px-4 py-6 sm:px-8">
                    {flash !== null && flash !== '' && (
                        <p role="status" className="mb-4 rounded-sm bg-green-soft px-4 py-3 text-ui text-ink">
                            {flash}
                        </p>
                    )}
                    {children}
                </main>
            </div>

            {/* The phone's tabs, as the field app has them, locked but one. */}
            <nav aria-label="Field portal" className="fixed inset-x-0 bottom-0 z-20 grid grid-cols-5 border-t border-rule bg-raised lg:hidden">
                <Link href="/agent/apply" aria-current="page" className="flex flex-col items-center gap-0.5 py-2 text-[0.7rem] font-bold text-gold-dark">
                    <Glyph d={APPLICATION_ICON} />
                    Apply
                </Link>
                {LOCKED.filter((item) => item.tab !== undefined).map((item) => (
                    <span key={item.label} aria-disabled="true" title={LOCKED_NOTE} className="relative flex flex-col items-center gap-0.5 py-2 text-[0.7rem] font-semibold text-faint">
                        <Glyph d={item.icon} />
                        {item.tab}
                        <Glyph d={LOCK_ICON} className="absolute top-1.5 right-[22%] size-3" />
                    </span>
                ))}
            </nav>
        </div>
    );
}
