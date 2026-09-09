import type { ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { cx } from '@/lib/cx';
import { GeoVerifyMark } from '@/components/GeoVerifyMark';

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
    const flash = usePage().props.flash.status;

    return (
        <div data-mode="daylight" className="min-h-dvh bg-surface text-ink">
            {/*
                A band, not a hairline. The portal used to open with a white
                header on a white page, which put the product's name and the
                page's own title in the same weight of ink on the same ground:
                nothing said where the register stopped and the business began.
                The dark band is the register, the rule under it is the accent,
                and everything below belongs to the person signed in.
            */}
            <header className="bg-ink text-inverse">
                <div
                    className={cx(
                        'mx-auto flex h-14 items-center justify-between gap-4 px-5',
                        width === 'form' ? 'max-w-xl' : 'max-w-5xl',
                    )}
                >
                    <Link
                        href={accountName === null ? '/portal/sign-in' : '/portal'}
                        className="flex items-center gap-2.5"
                    >
                        <GeoVerifyMark size={24} ink="light" />
                        <span className="font-display text-display-s">GeoVerify</span>
                        <span aria-hidden="true" className="mx-1 h-4 w-px bg-inverse/25" />
                        <span className="hidden text-label font-semibold tracking-[0.12em] text-inverse/65 uppercase sm:inline">
                            Business portal
                        </span>
                    </Link>

                    {accountName !== null && (
                        <div className="flex items-center gap-4">
                            <span className="hidden text-ui text-inverse/75 sm:inline">
                                {accountName}
                            </span>
                            <button
                                type="button"
                                onClick={() => {
                                    router.post('/portal/sign-out');
                                }}
                                className="min-h-touch rounded-sm px-2 text-ui text-inverse/75 hover:text-inverse"
                            >
                                Sign out
                            </button>
                        </div>
                    )}
                </div>
            </header>
            <div aria-hidden="true" className="h-[3px] bg-gold" />

            <main
                className={cx(
                    'mx-auto px-5 pb-24',
                    width === 'form' ? 'max-w-xl' : 'max-w-5xl',
                )}
            >
                {/*
                    Confirmation, which this shell went without until a party
                    could do something that needed one. A business that submits
                    a correction and sees the page redraw unchanged has no way
                    to tell whether it worked, and will submit it again.
                */}
                {flash !== null && (
                    <p
                        role="status"
                        className="mt-8 border-l-2 border-green bg-raised px-4 py-2.5 text-ui text-ink"
                    >
                        {flash}
                    </p>
                )}
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
