import { Link, router, usePage } from '@inertiajs/react';
import { GeoVerifyMark } from '@/components/GeoVerifyMark';

/**
 * Who is signed in, and the way out.
 *
 * The field client's bar, and only the field client's. It carries no navigation
 * because an officer has one place to be: the cell they are working. The console
 * moved to ConsoleShell, which puts the whole job in a sidebar and shows what is
 * waiting in each queue, and this stopped needing a variant the day it did.
 */
export function AppBar() {
    const user = usePage().props.auth.user;

    const signOut = () => {
        router.post('/logout');
    };

    return (
        <div className="flex flex-wrap items-center gap-x-6 gap-y-2 border-b border-rule px-4 py-2">
            <Link href="/field" className="flex items-center gap-2 font-display text-display-s text-ink">
                <GeoVerifyMark size={22} />
                GeoVerify
            </Link>

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
