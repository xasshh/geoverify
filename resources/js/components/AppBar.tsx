import { Link, router, usePage } from '@inertiajs/react';
import { GeoVerifyLockup } from '@/components/GeoVerifyMark';

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
        <div className="flex flex-wrap items-center gap-x-6 gap-y-2 border-b border-rule bg-raised px-4 py-3">
            <Link href="/field">
                <GeoVerifyLockup size={34} caption="Field enumeration" />
            </Link>

            <div className="ml-auto flex items-center gap-4">
                {user !== null && (
                    <span className="text-right">
                        <span className="block text-ui font-bold text-ink">{user.name}</span>
                        <span className="block numeric-mono text-label text-faint">
                            {user.staffRef ?? user.roleLabel}
                        </span>
                    </span>
                )}
                <button
                    type="button"
                    onClick={signOut}
                    className="min-h-touch rounded-sm border border-rule-strong bg-raised px-4 text-ui font-bold text-ink hover:bg-sunken"
                >
                    Sign out
                </button>
            </div>
        </div>
    );
}
