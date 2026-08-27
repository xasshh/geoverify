import type { ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { cx } from '@/lib/cx';

interface PortalShellProps {
    /** Shown in the bar. Absent before anyone has signed in. */
    accountName?: string | null;
    /** A short page title, above the content. */
    kicker?: string;
    title?: string;
    children: ReactNode;
    /** Narrow for a form, wide once there is a register to read. */
    width?: 'form' | 'page';
}

/**
 * The portal's frame.
 *
 * A register office rather than a field instrument: light ground, generous
 * measure, one thing on the screen at a time. Laid out at 360px first, because
 * that is the device most of this audience is holding, and widened rather than
 * rebuilt above it.
 *
 * Deliberately not the console's AppBar. That bar exists to move a supervisor
 * between four working views; this one exists to say where you are and how to
 * leave, and adding navigation a single-listing owner does not need is how a
 * calm surface becomes a busy one.
 */
export function PortalShell({
    accountName = null,
    kicker,
    title,
    children,
    width = 'form',
}: PortalShellProps) {
    return (
        <div data-mode="daylight" className="min-h-dvh bg-surface text-ink">
            <header className="border-b border-rule">
                <div
                    className={cx(
                        'mx-auto flex items-center justify-between gap-4 px-5 py-3.5',
                        width === 'form' ? 'max-w-xl' : 'max-w-5xl',
                    )}
                >
                    <Link
                        href={accountName === null ? '/portal/sign-in' : '/portal'}
                        className="flex items-center gap-2 font-display text-display-s text-ink"
                    >
                        <svg width="13" height="14" viewBox="0 0 11 12" aria-hidden="true">
                            <path
                                d="M5.5 0.5 10.5 3.25v5.5L5.5 11.5 0.5 8.75v-5.5z"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="1"
                            />
                        </svg>
                        Nigeria Business Directory
                    </Link>

                    {accountName !== null && (
                        <button
                            type="button"
                            onClick={() => {
                                router.post('/portal/sign-out');
                            }}
                            className="rounded-sm border border-rule-strong px-3 py-1.5 text-ui text-muted hover:border-ink hover:text-ink"
                        >
                            Sign out
                        </button>
                    )}
                </div>
            </header>

            <main
                className={cx(
                    'mx-auto px-5 pb-24',
                    width === 'form' ? 'max-w-xl' : 'max-w-5xl',
                )}
            >
                {(kicker !== undefined || title !== undefined) && (
                    <div className="mt-10 mb-8">
                        {kicker !== undefined && (
                            <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
                                {kicker}
                            </p>
                        )}
                        {title !== undefined && (
                            <h1 className="mt-1 font-display text-display-l text-ink">{title}</h1>
                        )}
                    </div>
                )}

                {children}
            </main>
        </div>
    );
}
