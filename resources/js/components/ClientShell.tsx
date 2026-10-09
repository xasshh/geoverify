import type { ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { cx } from '@/lib/cx';
import { GeoVerifyLockup } from '@/components/GeoVerifyMark';

/**
 * The commissioning client's frame.
 *
 * Deliberately quieter than the console's. A supervisor lives in their sidebar
 * for six hours a day and needs every queue depth in front of them; a client
 * opens this once a fortnight to answer one question, usually "how far along is
 * it". Two destinations, no counts, and the campaign itself carries the numbers.
 */
export function ClientShell({
    current,
    organisation,
    children,
}: {
    current: 'dashboard' | 'campaigns' | null;
    organisation?: { name?: string | null; shortCode?: string | null };
    children: ReactNode;
}) {
    const page = usePage();
    const flash = page.props.flash.status;

    const links = [
        { key: 'dashboard' as const, label: 'Active campaign', href: '/client' },
        { key: 'campaigns' as const, label: 'All campaigns', href: '/client/campaigns' },
    ];

    return (
        <div data-mode="daylight" className="min-h-dvh bg-surface text-ink">
            <header className="border-b border-rule bg-raised">
                <div className="mx-auto flex max-w-[1200px] flex-wrap items-center gap-x-8 gap-y-2 px-6 py-4">
                    <a href="/" aria-label="GeoVerify home">
                        <GeoVerifyLockup size={38} caption={organisation?.name ?? 'Client'} />
                    </a>

                    <nav className="flex items-center gap-1.5" aria-label="Client">
                        {links.map((link) => (
                            <Link
                                key={link.key}
                                href={link.href}
                                aria-current={link.key === current ? 'page' : undefined}
                                className={cx(
                                    'inline-flex min-h-touch items-center rounded-sm px-3.5 text-ui',
                                    link.key === current
                                        ? 'bg-gold-soft font-bold text-gold-dark'
                                        : 'font-semibold text-ink hover:bg-sunken',
                                )}
                            >
                                {link.label}
                            </Link>
                        ))}
                    </nav>

                    <button
                        type="button"
                        onClick={() => {
                            router.post('/client/sign-out');
                        }}
                        className="ml-auto min-h-touch rounded-sm border border-rule-strong bg-raised px-4 text-ui font-bold text-ink hover:bg-sunken"
                    >
                        Sign out
                    </button>
                </div>
            </header>

            {flash !== null && (
                <div className="mx-auto max-w-[1200px] px-6 pt-4">
                    <p className="rounded-sm bg-green-soft px-4 py-2.5 text-ui text-ink">
                        {flash}
                    </p>
                </div>
            )}

            <main className="mx-auto max-w-[1200px] px-6 pb-24">{children}</main>
        </div>
    );
}
