import type { ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import {
    adminNav,
    consoleNav,
    registryNav,
    type ConsoleNavItem,
    type ConsoleView,
} from '@/lib/consoleNav';
import { cx } from '@/lib/cx';
import { GeoVerifyLockup, GeoVerifyMark } from '@/components/GeoVerifyMark';

/**
 * The console, given a standing frame.
 *
 * Five views across the top of a page put the person somewhere without telling
 * them where else they could be: a supervisor who has never opened Claims has no
 * reason to learn it exists. A sidebar keeps the whole job on screen, says what
 * each view is for, and carries the two numbers that decide what to open next.
 *
 * Those numbers are the point. A queue nobody can see the depth of is a queue
 * that gets worked when somebody remembers, and the whole argument of this
 * console is that review happens in the morning rather than at the end of the
 * week.
 */

function Mark({ path, className }: { path: string; className?: string }) {
    return (
        <svg
            width="18"
            height="18"
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.3"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            className={className}
        >
            <path d={path} />
        </svg>
    );
}

/**
 * How many are waiting, where any are.
 *
 * Absent at zero rather than shown as "0". A row that reads "Review 0" is a row
 * that has to be parsed before it can be dismissed, and the whole value of a
 * count here is that it is dismissed without being read.
 */
function Waiting({ count }: { count: number }) {
    if (count <= 0) {
        return null;
    }

    return (
        <span
            className="ml-auto flex h-6 min-w-6 shrink-0 items-center justify-center rounded-full bg-gold px-1.5 text-label font-extrabold tracking-normal text-on-accent"
            aria-label={`${String(count)} waiting`}
        >
            {count}
        </span>
    );
}

function NavRow({
    item,
    current,
    waiting,
    dense = false,
}: {
    item: ConsoleNavItem;
    current: boolean;
    waiting: number;
    dense?: boolean;
}) {
    return (
        <Link
            href={item.href}
            aria-current={current ? 'page' : undefined}
            className={cx(
                'group flex items-center gap-3 rounded-sm px-3.5 transition-colors',
                dense ? 'min-h-touch py-1.5' : 'py-2.5',
                current
                    ? 'bg-gold-soft text-gold-dark'
                    : 'text-ink hover:bg-sunken',
            )}
        >
            <Mark path={item.icon} className={current ? 'text-gold-dark' : 'text-muted'} />

            <span className="flex min-w-0 flex-col">
                <span className={cx('text-ui', current ? 'font-bold' : 'font-semibold')}>
                    {item.label}
                </span>
                {!dense && (
                    <span
                        className={cx(
                            'truncate text-[0.75rem] leading-snug',
                            current ? 'text-gold-dark/80' : 'text-faint',
                        )}
                    >
                        {item.caption}
                    </span>
                )}
            </span>

            <Waiting count={waiting} />
        </Link>
    );
}

/**
 * The five rows on their own, with the counts handed in.
 *
 * Separated from the shell so the design gallery can show a queue that is
 * backed up beside one that is clear without signing anybody in. The shell
 * passes what the server shared; nothing else has two versions of this markup.
 */
export type QueueCounts = {
    review: number;
    claims: number;
    corrections: number;
    escalations: number;
    orders: number;
    messages: number;
};

export function ConsoleNav({
    current,
    counts,
    dense = false,
    items = consoleNav(),
}: {
    current: ConsoleView | null;
    counts: QueueCounts | null;
    dense?: boolean;
    items?: ConsoleNavItem[];
}) {
    return (
        <>
            {items.map((item) => (
                <NavRow
                    key={item.key}
                    item={item}
                    current={item.key === current}
                    waiting={item.queue === undefined || counts === null ? 0 : counts[item.queue]}
                    dense={dense}
                />
            ))}
        </>
    );
}

/** Two letters on the soft accent, for the person the session belongs to. */
export function Initials({ name, size = 44 }: { name: string; size?: number }) {
    const letters = name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase() ?? '')
        .join('');

    return (
        <span
            aria-hidden="true"
            style={{ width: size, height: size }}
            className="flex shrink-0 items-center justify-center rounded-full bg-gold-soft text-ui font-extrabold text-gold-dark"
        >
            {letters}
        </span>
    );
}

interface ConsoleShellProps {
    current: ConsoleView | null;
    /**
     * Daylight everywhere except the map, which is read against dark ground so
     * the geometry it draws carries the contrast rather than competing with it.
     */
    mode?: 'daylight' | 'dusk';
    /**
     * For the screens that own the viewport and scroll inside themselves. The
     * map is one: a page that scrolls under a map is a map you cannot pan.
     */
    fill?: boolean;
    children: ReactNode;
}

export function ConsoleShell({
    current,
    mode = 'daylight',
    fill = false,
    children,
}: ConsoleShellProps) {
    const page = usePage();
    const user = page.props.auth.user;
    const queues = page.props.console;

    const signOut = () => {
        router.post('/logout');
    };

    return (
        <div
            data-mode={mode}
            className={cx(
                'bg-surface text-ink lg:grid lg:grid-cols-[280px_minmax(0,1fr)]',
                fill ? 'h-dvh overflow-hidden' : 'min-h-dvh',
            )}
        >
            {/* The sidebar proper, from lg up. Sticky rather than scrolling with
                the page: the counts are the reason it exists, and a count you
                have to scroll back up to read is a count you stop checking. */}
            {/* Dusk, as the supervisor board draws it: the one dark surface in
                an otherwise daylight console, so the frame reads as the frame. */}
            <aside
                data-mode="dusk"
                className="hidden border-r border-rule bg-surface text-ink lg:sticky lg:top-0 lg:flex lg:h-dvh lg:flex-col"
            >
                <Link href="/console" className="flex items-center px-7 pt-7 pb-6">
                    <GeoVerifyLockup
                        caption={user?.role === 'admin' ? 'Administration' : 'Field · Supervisor'}
                    />
                </Link>

                <nav className="flex flex-col gap-1 overflow-y-auto px-5 pb-4" aria-label="Console">
                    <ConsoleNav current={current} counts={queues} />

                    <p className="mt-5 mb-1 px-3.5 text-label font-bold tracking-[0.05em] text-faint uppercase">
                        Registry
                    </p>
                    <ConsoleNav current={current} counts={queues} items={registryNav()} />

                    {user?.role === 'admin' && (
                        <>
                            <p className="mt-5 mb-1 px-3.5 text-label font-bold tracking-[0.05em] text-faint uppercase">
                                In house
                            </p>
                            <ConsoleNav
                                current={current}
                                counts={queues}
                                items={adminNav()}
                            />
                        </>
                    )}
                </nav>

                {user !== null && (
                    <div className="mt-auto px-5 pb-6">
                        <div className="flex items-center gap-3 px-2 py-3">
                            <Initials name={user.name} />
                            <span className="flex min-w-0 flex-col">
                                <span className="truncate text-ui font-bold text-ink">
                                    {user.name}
                                </span>
                                <span className="truncate text-[0.75rem] text-faint">
                                    <span className="numeric-mono">{user.staffRef ?? '.'}</span>{' '}
                                    &middot; {user.roleLabel}
                                </span>
                            </span>
                        </div>
                        <button
                            type="button"
                            onClick={signOut}
                            className="min-h-touch w-full rounded-sm border border-rule-strong bg-raised px-3 text-ui font-bold text-ink hover:bg-sunken"
                        >
                            Sign out
                        </button>
                    </div>
                )}
            </aside>

            {/* Below lg the same five become a scrolling strip. A drawer would
                hide the counts behind a tap, which is the one thing they cannot
                afford to be. */}
            <div
                className={cx(
                    'flex flex-col border-b border-rule bg-raised lg:hidden',
                    fill && 'shrink-0',
                )}
            >
                <div className="flex items-center gap-2 px-4 py-2">
                    <GeoVerifyMark size={28} />
                    <span className="font-wordmark text-[1.125rem] font-bold text-logo">GeoVerify</span>
                    {user !== null && (
                        <button
                            type="button"
                            onClick={signOut}
                            className="ml-auto min-h-touch rounded-sm border border-rule-strong bg-raised px-3 text-ui font-bold text-ink"
                        >
                            Sign out
                        </button>
                    )}
                </div>

                <nav
                    className="flex gap-1 overflow-x-auto px-2 pb-2 [&>a]:shrink-0"
                    aria-label="Console"
                >
                    <ConsoleNav current={current} counts={queues} dense />
                    <ConsoleNav current={current} counts={queues} items={registryNav()} dense />
                    {user?.role === 'admin' && (
                        <ConsoleNav
                            current={current}
                            counts={queues}
                            items={adminNav()}
                            dense
                        />
                    )}
                </nav>
            </div>

            <main className={cx(fill ? 'flex min-h-0 flex-col' : 'min-w-0')}>{children}</main>
        </div>
    );
}
