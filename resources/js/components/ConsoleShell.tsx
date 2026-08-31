import type { ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import {
    adminNav,
    consoleNav,
    type ConsoleNavItem,
    type ConsoleView,
} from '@/lib/consoleNav';
import { cx } from '@/lib/cx';
import { GeoVerifyMark } from '@/components/GeoVerifyMark';

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
            width="16"
            height="16"
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.1"
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
            className="ml-auto shrink-0 rounded-sm bg-gold px-1.5 py-0.5 numeric-mono text-label font-semibold text-on-accent"
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
                'group flex items-center gap-2.5 border-l-2 py-2 pr-2 pl-3 transition-colors',
                current
                    ? 'border-gold bg-raised text-ink'
                    : 'border-transparent text-muted hover:border-rule-strong hover:bg-raised hover:text-ink',
            )}
        >
            <Mark path={item.icon} className={current ? 'text-gold' : 'text-faint'} />

            <span className="flex min-w-0 flex-col">
                <span className={cx('text-ui', current && 'font-semibold')}>{item.label}</span>
                {!dense && (
                    <span className="truncate text-label text-faint">{item.caption}</span>
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
                'bg-surface text-ink lg:grid lg:grid-cols-[248px_minmax(0,1fr)]',
                fill ? 'h-dvh overflow-hidden' : 'min-h-dvh',
            )}
        >
            {/* The sidebar proper, from lg up. Sticky rather than scrolling with
                the page: the counts are the reason it exists, and a count you
                have to scroll back up to read is a count you stop checking. */}
            <aside className="hidden border-r border-rule lg:sticky lg:top-0 lg:flex lg:h-dvh lg:flex-col">
                <Link
                    href="/console/coverage"
                    className="flex items-center gap-2 border-b border-rule px-4 py-3"
                >
                    <GeoVerifyMark size={24} />
                    <span className="flex flex-col">
                        <span className="font-display text-display-s leading-none text-ink">
                            GeoVerify
                        </span>
                        <span className="text-label tracking-[0.12em] text-faint uppercase">
                            {user?.role === 'admin' ? 'Administration' : 'Supervisor console'}
                        </span>
                    </span>
                </Link>

                <nav className="flex flex-col py-3" aria-label="Console">
                    <ConsoleNav current={current} counts={queues} />

                    {user?.role === 'admin' && (
                        <>
                            <p className="mt-4 mb-1 px-3 text-label font-semibold tracking-[0.12em] text-faint uppercase">
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
                    <div className="mt-auto border-t border-rule px-4 py-3">
                        <p className="text-ui text-ink">{user.name}</p>
                        <p className="numeric-mono text-label text-faint">
                            {user.staffRef ?? '.'} &middot; {user.roleLabel}
                        </p>
                        <button
                            type="button"
                            onClick={signOut}
                            className="mt-2 min-h-touch w-full rounded-sm border border-rule-strong px-3 text-ui text-muted hover:bg-raised hover:text-ink"
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
                    'flex flex-col border-b border-rule lg:hidden',
                    fill && 'shrink-0',
                )}
            >
                <div className="flex items-center gap-2 px-4 py-2">
                    <GeoVerifyMark size={22} />
                    <span className="font-display text-display-s text-ink">GeoVerify</span>
                    {user !== null && (
                        <button
                            type="button"
                            onClick={signOut}
                            className="ml-auto min-h-touch rounded-sm border border-rule-strong px-3 text-ui text-muted"
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
