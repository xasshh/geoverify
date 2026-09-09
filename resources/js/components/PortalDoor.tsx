import type { ReactNode } from 'react';
import { GeoVerifyMark } from '@/components/GeoVerifyMark';

/**
 * The front door, which is not a page of the portal.
 *
 * Sign-in used to render inside PortalShell, so the first thing a business owner
 * ever saw was an inner page of an application they had not entered: a header
 * bar, a kicker, a title, and one field adrift in a column. It read as a form
 * somebody had left open rather than as the entrance to a register.
 *
 * So the door has its own frame. The register speaks on the left, in its own
 * colour, and says what it is and what can be done here before asking for
 * anything. The right side is quiet, because it is the half doing the work.
 *
 * Two columns above 900px and one below it, panel first: on a handset the
 * register still introduces itself, it just does it in three lines instead of a
 * full height panel.
 */
export function PortalDoor({
    heading,
    children,
    aside,
}: {
    heading: string;
    children: ReactNode;
    /** What the register says about itself. Omitted on the inner steps. */
    aside?: ReactNode;
}) {
    return (
        <div
            data-mode="daylight"
            className="flex min-h-dvh flex-col bg-surface text-ink lg:flex-row"
        >
            <div className="flex shrink-0 flex-col bg-gold px-7 py-8 text-inverse sm:px-11 sm:py-12 lg:w-[38%] lg:max-w-[470px] lg:py-12">
                <div className="flex items-center gap-3">
                    <GeoVerifyMark size={34} title="GeoVerify" ink="light" />
                    <span className="text-label font-semibold tracking-[0.12em] text-inverse/70 uppercase">
                        Nigeria Business Directory
                    </span>
                </div>

                <h1 className="mt-6 font-display text-display-l sm:mt-7 sm:text-display-xl">
                    GeoVerify
                </h1>
                <p className="mt-3 max-w-[34ch] text-body text-inverse/75">
                    The national register of businesses, built from what officers recorded at the
                    door. Sign in to manage the entry for a business you own.
                </p>

                {aside}
            </div>

            <div className="flex flex-grow flex-col px-7 py-10 sm:px-11 sm:py-14 lg:px-16 lg:py-[72px]">
                <div className="w-full max-w-[420px]">
                    <h2 className="font-display text-display-m">{heading}</h2>
                    {children}
                </div>

                <div className="flex-grow" />

                <div className="mt-12 flex gap-6 border-t border-rule pt-4 text-table text-faint">
                    <span>A register of businesses, not a licence or an endorsement.</span>
                </div>
            </div>
        </div>
    );
}

/**
 * The three things a person can do here, stated before they are asked for a
 * number. A door that only asks is a door nobody knows they are at.
 */
export function DoorTasks() {
    const tasks = [
        {
            title: 'Claim a listing an officer recorded',
            detail: 'Proved by a code sent to the number on the record.',
            icon: (
                <>
                    <path d="M10 2.5 3 6v4.5c0 3.6 2.8 6.2 7 7 4.2-.8 7-3.4 7-7V6l-7-3.5Z" />
                    <path d="m7.2 9.8 2 2 3.6-3.8" />
                </>
            ),
        },
        {
            title: 'Ask for a correction',
            detail: "A supervisor rules on it. The officer's record is kept.",
            icon: (
                <>
                    <path d="M3 16.2V13l9.4-9.4a1.6 1.6 0 0 1 2.3 0l1.7 1.7a1.6 1.6 0 0 1 0 2.3L7 17H3.8" />
                    <path d="M11.4 5.2 14.8 8.6" />
                </>
            ),
        },
        {
            title: 'Buy a verification visit',
            detail: 'An officer attends and issues a certificate anyone can check.',
            icon: (
                <>
                    <path d="M10 2.6 2.8 6.1v4.4c0 3.6 2.9 6.3 7.2 7.1 4.3-.8 7.2-3.5 7.2-7.1V6.1L10 2.6Z" />
                    <circle cx="10" cy="9.6" r="2.3" />
                    <path d="M10 11.9v2.6" />
                </>
            ),
        },
    ];

    return (
        <>
            <div className="mt-8 mb-6 h-px bg-inverse/20 lg:mt-9 lg:mb-7" />

            <span className="text-label font-semibold tracking-[0.12em] text-inverse/60 uppercase">
                What you can do here
            </span>

            <ul className="mt-4 flex flex-col gap-5">
                {tasks.map((task) => (
                    <li key={task.title} className="flex gap-3.5">
                        <svg
                            width="20"
                            height="20"
                            viewBox="0 0 20 20"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="1.5"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            aria-hidden="true"
                            className="mt-0.5 shrink-0 text-inverse/80"
                        >
                            {task.icon}
                        </svg>
                        <span className="flex flex-col gap-0.5">
                            <span className="text-ui font-medium">{task.title}</span>
                            <span className="text-ui text-inverse/65">{task.detail}</span>
                        </span>
                    </li>
                ))}
            </ul>

            <div className="flex-grow" />

            <p className="mt-8 max-w-[40ch] text-table text-inverse/60">
                Being on the register is not the same as being published. Nothing about a business
                appears publicly until its owner says so.
            </p>
        </>
    );
}
