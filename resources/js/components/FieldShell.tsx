import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { GeoVerifyLockup } from '@/components/GeoVerifyMark';
import { cx } from '@/lib/cx';
import { ago, type OfficerDay } from '@/lib/fieldDay';
import { useInbox } from '@/lib/offline/messages';
import { useOfflineQueue } from '@/lib/offline/useOfflineQueue';

type FieldNav = 'today' | 'map' | 'capture' | 'records' | 'inbox' | 'brief' | 'device';

const ICON: Record<FieldNav, string> = {
    today: 'M3 10.5 12 3l9 7.5M5.5 9v11h13V9',
    map: 'M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11zM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
    capture: 'M12 5v14M5 12h14',
    records: 'M6 3h9l3 3v15H6zM9 9h6M9 13h6M9 17h4',
    inbox: 'M4 5h16v11H9l-5 4z',
    brief: 'M6 3h9l3 3v15H6zM9 12h6M9 16h6',
    device: 'M4 12a8 8 0 0 1 14-5.3M20 12a8 8 0 0 1-14 5.3M18 3v4h-4M6 21v-4h4',
};

function Icon({ name, className }: { name: FieldNav; className?: string }) {
    return (
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className={cx('shrink-0', className)}>
            <path d={ICON[name]} />
        </svg>
    );
}

function greeting(): string {
    const hour = new Date().getHours();

    return hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
}

function shiftLength(startedAt: string | null): string | null {
    if (startedAt === null) {
        return null;
    }

    const minutes = Math.max(0, Math.round((Date.now() - new Date(startedAt).getTime()) / 60_000));

    return `${String(Math.floor(minutes / 60))}h ${String(minutes % 60)}m`;
}

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter((part) => /[A-Za-z]/.test(part))
        .slice(0, 2)
        .map((part) => part.replace(/[^A-Za-z]/g, '').charAt(0).toUpperCase())
        .join('');
}

/** Battery, where the browser will say. Chrome on Android does; others do not. */
function useBattery(): number | null {
    const [level, setLevel] = useState<number | null>(null);

    useEffect(() => {
        const nav = navigator as Navigator & { getBattery?: () => Promise<{ level: number }> };

        if (nav.getBattery !== undefined) {
            void nav.getBattery().then((battery) => {
                setLevel(Math.round(battery.level * 100));
            });
        }
    }, []);

    return level;
}

/**
 * The officer's frame, to the enumeration mockup.
 *
 * Desktop: the sidebar (Today, My map, New capture, My records, Supervisor
 * inbox, Campaign brief, Sync & device), the device card and the officer at the
 * foot, and a header band with the greeting, the campaign, the shift and "Call
 * supervisor". Phone: the status chip on top and four tabs at the bottom.
 *
 * The map is not part of this frame. It is the capture screen, unchanged.
 */
export function FieldShell({
    day,
    current,
    title,
    children,
}: {
    day: OfficerDay;
    current: FieldNav;
    title?: string;
    children: ReactNode;
}) {
    const flash = usePage().props.flash.status;
    const queue = useOfflineQueue();
    const inbox = useInbox();
    const battery = useBattery();
    const [, tick] = useState(0);

    // The shift clock and "2 min ago" move on their own.
    useEffect(() => {
        const timer = window.setInterval(() => {
            tick((n) => n + 1);
        }, 60_000);

        return () => {
            window.clearInterval(timer);
        };
    }, []);

    // Offline, part one: keep a copy of every field screen as soon as this
    // shell loads with signal. The worker only caches what passes through it,
    // and the page that installed the worker never did, so an officer whose
    // first open of the app was also their last with signal would otherwise
    // have nothing to fall back on. Written straight to the worker's cache.
    const warm = [
        '/field',
        '/field/inbox',
        '/field/records',
        '/field/brief',
        '/field/device',
        ...day.cells.next.map((cell) => `/field/assignments/${String(cell.assignmentId)}/capture`),
        ...day.jobs.map((job) => `/field/jobs/${String(job.id)}`),
    ].join('|');

    useEffect(() => {
        if (!navigator.onLine || !('caches' in window)) {
            return;
        }

        void caches
            .open('geoverify-field-pages')
            .then((cache) => Promise.all(warm.split('|').map((url) => cache.add(url).catch(() => undefined))))
            .catch(() => undefined);
    }, [warm]);

    // Offline, part two: an in-app visit asks for Inertia's JSON, which the
    // worker cannot answer from an HTML copy (the server varies on
    // X-Inertia). When a visit fails for want of a network, load the page
    // whole instead, and the worker serves the copy kept above.
    useEffect(() => {
        let target: string | null = null;

        // Known offline before the request leaves: go straight to a whole
        // page load of a GET, rather than waiting for the request to fail.
        const stopBefore = router.on('before', (event) => {
            const visit = event.detail.visit;

            if (!navigator.onLine && visit.method === 'get') {
                window.location.href = visit.url.href;

                return false;
            }

            return undefined;
        });

        const stopStart = router.on('start', (event) => {
            target = event.detail.visit.method === 'get' ? event.detail.visit.url.href : null;
        });

        // Keyed on the request failing, not on navigator.onLine, which says
        // true on a handset whose radio is up with no data behind it, and on a
        // page the worker served from its copy. A request with no response at
        // all is the signal; a server that answered with an error is not.
        const stopException = router.on('exception', (event) => {
            const failure = event.detail.exception as Error & { response?: unknown };

            if (target !== null && failure.response === undefined) {
                window.location.href = target;

                return false;
            }

            return undefined;
        });

        return () => {
            stopBefore();
            stopStart();
            stopException();
        };
    }, []);

    const unread = Math.max(inbox.unread, inbox.messages.length === 0 ? day.unread : 0);
    const synced = queue.online && queue.queued === 0 && !queue.syncing;
    const syncLabel = !queue.online ? 'Offline' : queue.syncing ? 'Syncing' : queue.queued > 0 ? `${String(queue.queued)} waiting` : 'Synced';
    const firstName = day.officer.name.split(/\s+/).filter((p) => !p.endsWith('.'))[0] ?? day.officer.name;
    const shift = shiftLength(day.shiftStartedAt);
    const nextCapture = day.cells.next[0];
    const captureHref = nextCapture === undefined ? '/field/map' : `/field/assignments/${String(nextCapture.assignmentId)}/capture`;

    const nav: { key: FieldNav; label: string; href: string; count?: number }[] = [
        { key: 'today', label: 'Today', href: '/field' },
        // Straight to the capture URL rather than through /field/map: a
        // redirect cannot be answered from the offline copy.
        { key: 'map', label: 'My map', href: captureHref },
        { key: 'capture', label: 'New capture', href: captureHref },
        { key: 'records', label: 'My records', href: '/field/records', count: day.returned.count },
        { key: 'inbox', label: 'Supervisor inbox', href: '/field/inbox', count: unread },
        { key: 'brief', label: 'Campaign brief', href: '/field/brief' },
        { key: 'device', label: 'Sync & device', href: '/field/device' },
    ];

    const tabs = nav.filter((item) => ['today', 'map', 'records', 'inbox'].includes(item.key));

    const signOut = () => {
        // The offline copies of these pages hold this officer's work. A shared
        // handset must not show them to the next person.
        if ('caches' in window) {
            void caches.delete('geoverify-field-pages');
        }

        router.post('/logout');
    };

    return (
        <div data-mode="daylight" className="min-h-dvh bg-surface text-ink lg:grid lg:grid-cols-[260px_minmax(0,1fr)]">
            <Head title={title ?? 'Today'} />

            <aside className="hidden border-r border-rule bg-raised lg:sticky lg:top-0 lg:flex lg:h-dvh lg:flex-col">
                <Link href="/field" className="flex items-center px-6 pt-6 pb-6">
                    <GeoVerifyLockup size={36} caption="Field · Officer" />
                </Link>
                <nav className="flex flex-col gap-1 px-4" aria-label="Field">
                    {nav.map((item) => (
                        <Link
                            key={item.key}
                            href={item.href}
                            aria-current={item.key === current ? 'page' : undefined}
                            className={cx(
                                'flex min-h-[46px] items-center gap-3 rounded-sm px-3.5 text-ui',
                                item.key === current ? 'bg-gold-soft font-bold text-gold-dark' : 'font-semibold text-ink hover:bg-sunken',
                            )}
                        >
                            <Icon name={item.key} />
                            {item.label}
                            {item.count !== undefined && item.count > 0 && (
                                <span className={cx(
                                    'ml-auto flex h-6 min-w-6 items-center justify-center rounded-full px-1.5 text-label font-extrabold text-on-accent',
                                    item.key === 'inbox' ? 'bg-alert' : 'bg-amber',
                                )}>
                                    {item.count}
                                </span>
                            )}
                        </Link>
                    ))}
                </nav>

                <div className="mt-auto flex flex-col gap-3 px-4 pb-5">
                    <div className="rounded-card bg-sunken px-4 py-3.5 text-table">
                        <p className="flex items-center justify-between font-bold text-ink">
                            Device
                            <span className={cx('flex items-center gap-1.5 font-bold', synced ? 'text-green' : queue.online ? 'text-amber-ink' : 'text-alert')}>
                                <span className={cx('size-2 rounded-full', synced ? 'bg-green' : queue.online ? 'bg-amber' : 'bg-alert')} aria-hidden="true" />
                                {syncLabel}
                            </span>
                        </p>
                        <p className="mt-1 text-muted">
                            Last sync {ago(queue.lastSyncAt)} · {queue.queued} {queue.queued === 1 ? 'record' : 'records'} waiting
                        </p>
                        {battery !== null && <p className="mt-1 text-muted">Battery {battery}%</p>}
                    </div>
                    <div className="flex items-center gap-3 px-1">
                        <span className="flex size-10 items-center justify-center rounded-sm bg-gold-soft font-extrabold text-gold-dark">{initials(day.officer.name)}</span>
                        <span className="min-w-0">
                            <span className="block truncate text-ui font-bold text-ink">{day.officer.name}</span>
                            <span className="block numeric-mono text-table text-muted">{day.officer.staffRef ?? 'Field officer'}</span>
                        </span>
                        <button type="button" onClick={signOut} className="ml-auto text-table font-bold text-muted hover:text-ink">
                            Sign out
                        </button>
                    </div>
                </div>
            </aside>

            <div className="min-w-0 pb-24 lg:pb-10">
                {/* Phone: the status chip and the greeting in one band. */}
                <header className="flex items-center justify-between border-b border-rule bg-raised px-4 py-3 lg:hidden">
                    <GeoVerifyLockup size={30} caption={`Field · ${day.officer.staffRef ?? 'Officer'}`} />
                    <span className={cx('flex items-center gap-1.5 rounded-full px-3 py-1.5 text-table font-bold', synced ? 'bg-green-soft text-green' : queue.online ? 'bg-amber-soft text-amber-ink' : 'bg-alert-soft text-alert-ink')}>
                        <span className={cx('size-2 rounded-full', synced ? 'bg-green' : queue.online ? 'bg-amber' : 'bg-alert')} aria-hidden="true" />
                        {syncLabel}
                    </span>
                </header>

                <header className="border-b border-rule bg-raised px-4 py-4 lg:px-8 lg:py-5">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="min-w-0">
                            <h1 className="font-display text-display-m text-ink lg:text-display-l">
                                {title === undefined ? `${greeting()}, ${firstName}` : title}
                            </h1>
                            {day.campaign !== null && (
                                <p className="mt-1.5 flex flex-wrap items-center gap-2 text-table text-muted">
                                    <span className="rounded-sm bg-gold-soft px-2 py-0.5 font-bold text-gold-dark">Active campaign</span>
                                    <span className="font-bold text-ink">{day.campaign.name}</span>
                                    {day.campaign.day !== null && day.campaign.days !== null && (
                                        <span>· Day {day.campaign.day} of {day.campaign.days}</span>
                                    )}
                                    {day.campaign.area !== null && <span>· {day.campaign.area}</span>}
                                </p>
                            )}
                        </div>
                        <div className="hidden items-center gap-3 lg:flex">
                            {shift !== null && (
                                <span className="flex min-h-touch items-center gap-2 rounded-sm border border-rule-strong px-4 text-ui font-bold text-ink">
                                    On shift {shift}
                                </span>
                            )}
                            {day.supervisor?.phone != null && (
                                <a href={`tel:${day.supervisor.phone}`} className="flex min-h-touch items-center gap-2 rounded-sm bg-ink px-4 text-ui font-extrabold text-inverse hover:bg-graphite">
                                    Call supervisor
                                </a>
                            )}
                            <Link href="/field/inbox" aria-label={`${String(unread)} unread`} className="relative flex size-11 items-center justify-center rounded-sm border border-rule-strong text-ink hover:bg-sunken">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
                                    <path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15zM10 20a2 2 0 0 0 4 0" />
                                </svg>
                                {unread > 0 && <span className="absolute top-2 right-2 size-2.5 rounded-full bg-alert" />}
                            </Link>
                        </div>
                    </div>
                </header>

                <main className="px-4 py-5 lg:px-8 lg:py-7">
                    {flash !== null && (
                        <p role="status" className="mb-5 rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green">
                            {flash}
                        </p>
                    )}
                    {children}
                </main>
            </div>

            {/* Phone: four tabs, thumb reach. */}
            <nav className="fixed inset-x-0 bottom-0 z-20 grid grid-cols-4 border-t border-rule bg-raised pb-[env(safe-area-inset-bottom)] lg:hidden" aria-label="Field">
                {tabs.map((item) => (
                    <Link
                        key={item.key}
                        href={item.href}
                        aria-current={item.key === current ? 'page' : undefined}
                        className={cx('relative flex min-h-[60px] flex-col items-center justify-center gap-1 text-table font-bold', item.key === current ? 'text-gold-dark' : 'text-muted')}
                    >
                        <Icon name={item.key} />
                        {item.key === 'map' ? 'Map' : item.key === 'inbox' ? 'Inbox' : item.key === 'records' ? 'Records' : item.label}
                        {item.count !== undefined && item.count > 0 && (
                            <span className="absolute top-1.5 left-1/2 ml-2 flex h-5 min-w-5 items-center justify-center rounded-full bg-alert px-1 text-[11px] font-extrabold text-on-accent">
                                {item.count}
                            </span>
                        )}
                    </Link>
                ))}
            </nav>
        </div>
    );
}
