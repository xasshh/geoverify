import { Link, router, usePage } from '@inertiajs/react';
import { cx } from '@/lib/cx';

interface AppBarProps {
    /** Console links across, field keeps it to identity and sign out. */
    variant: 'console' | 'field';
    links?: Array<{ label: string; href: string; current?: boolean }>;
}

/**
 * Who is signed in, and the way out.
 *
 * Every screen needs this: without it a supervisor cannot move between the
 * coverage map and the assignment list, and an officer handing a phone back has
 * no way to sign out of it.
 */
export function AppBar({ variant, links = [] }: AppBarProps) {
    const user = usePage().props.auth.user;

    const signOut = () => {
        router.post('/logout');
    };

    return (
        <div
            className={cx(
                'flex flex-wrap items-center gap-x-6 gap-y-2 border-b border-rule px-6 py-2',
                variant === 'field' && 'px-4',
            )}
        >
            <Link
                href={variant === 'field' ? '/field' : '/console/coverage'}
                className="flex items-center gap-2 font-display text-display-s text-ink"
            >
                <svg width="13" height="14" viewBox="0 0 11 12" aria-hidden="true">
                    <path
                        d="M5.5 0.5 10.5 3.25v5.5L5.5 11.5 0.5 8.75v-5.5z"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="1"
                        className="text-gold"
                    />
                </svg>
                GeoVerify
            </Link>

            {links.length > 0 && (
                <nav className="flex flex-wrap items-center gap-4" aria-label="Console">
                    {links.map((link) => (
                        <Link
                            key={link.href}
                            href={link.href}
                            aria-current={link.current === true ? 'page' : undefined}
                            className={cx(
                                'text-ui underline-offset-4 hover:underline',
                                link.current === true ? 'font-semibold text-ink' : 'text-muted',
                            )}
                        >
                            {link.label}
                        </Link>
                    ))}
                </nav>
            )}

            <div className="ml-auto flex items-center gap-4">
                {user !== null && (
                    <span className="text-right">
                        <span className="block text-ui text-ink">{user.name}</span>
                        <span className="block numeric-mono text-label text-faint">
                            {user.staffRef ?? user.roleLabel}
                        </span>
                    </span>
                )}
                <button
                    type="button"
                    onClick={signOut}
                    className="min-h-touch rounded-sm border border-rule-strong px-3 text-ui text-muted hover:bg-raised hover:text-ink"
                >
                    Sign out
                </button>
            </div>
        </div>
    );
}
