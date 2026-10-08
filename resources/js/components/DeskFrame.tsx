import { Link, router } from '@inertiajs/react';
import { GeoVerifyLockup } from '@/components/GeoVerifyMark';

/** The page frame for the desk: brand, where you are, sign out. */
export function DeskFrame({ title, children, back }: { title: string; children: React.ReactNode; back?: string }) {
    return (
        <div data-mode="daylight" className="min-h-dvh bg-sunken text-ink">
            <header className="flex items-center gap-4 border-b border-rule bg-raised px-5 py-3">
                <GeoVerifyLockup size={30} caption="Desk" />
                {back !== undefined && (
                    <Link href={back} className="text-label text-gold-dark underline underline-offset-2">
                        All ground
                    </Link>
                )}
                <p className="min-w-0 flex-1 truncate text-ui font-extrabold">{title}</p>
                <button
                    type="button"
                    onClick={() => {
                        router.post('/logout');
                    }}
                    className="text-label text-muted underline underline-offset-2"
                >
                    Sign out
                </button>
            </header>
            {children}
        </div>
    );
}
