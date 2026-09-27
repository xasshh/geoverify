import type { ReactNode } from 'react';

interface SheetProps {
    title: string;
    /** Sits beside the title. Coordinates and accuracy go here, in mono. */
    meta?: ReactNode;
    children: ReactNode;
    /** Pinned to the bottom, always reachable one-handed. */
    footer?: ReactNode;
    /** Reassurance under the primary action. Never a spinner. */
    footerNote?: string;
}

/**
 * The field app's detail surface.
 *
 * An observation form, not an edit form: §3 requires that a revisit records a new
 * observation rather than overwriting one, so prior state appears here as read-only
 * history and the officer records what is true today.
 */
export function Sheet({ title, meta, children, footer, footerNote }: SheetProps) {
    return (
        <section
            className="flex flex-col overflow-hidden rounded-t-sheet border border-rule-strong bg-surface"
            style={{ boxShadow: 'var(--gv-shadow-sheet)' }}
            aria-label={title}
        >
            <header className="shrink-0 border-b border-rule px-4 pt-2.5 pb-3">
                <div
                    aria-hidden="true"
                    className="mx-auto mb-3 h-1 w-10 rounded-full bg-rule-strong"
                />
                <h2 className="font-display text-display-s text-ink">{title}</h2>
                {meta !== undefined && (
                    <div className="mt-1 numeric-mono text-mono text-muted">{meta}</div>
                )}
            </header>

            <div className="flex-1 overflow-y-auto">{children}</div>

            {footer !== undefined && (
                <footer className="shrink-0 border-t border-rule bg-raised px-4 py-3">
                    {footer}
                    {footerNote !== undefined && (
                        <p className="mt-2 text-center text-ui text-muted">{footerNote}</p>
                    )}
                </footer>
            )}
        </section>
    );
}

interface SheetSectionProps {
    label: string;
    /** A count against a denominator, for example enterprises against unit count. */
    trailing?: ReactNode;
    children: ReactNode;
}

export function SheetSection({ label, trailing, children }: SheetSectionProps) {
    return (
        <div className="border-b border-rule px-4 py-3 last:border-b-0">
            <div className="mb-2.5 flex items-baseline justify-between gap-3">
                <h3 className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                    {label}
                </h3>
                {trailing !== undefined && (
                    <span className="numeric-mono text-ui text-muted">{trailing}</span>
                )}
            </div>
            {children}
        </div>
    );
}

interface PriorObservationProps {
    observedOn: string;
    summary: string;
}

/**
 * What this structure looked like last time. Visibly historical and not editable:
 * the officer sees what changed since March without being able to rewrite March.
 */
export function PriorObservation({ observedOn, summary }: PriorObservationProps) {
    return (
        <div className="border-b border-rule bg-sunken px-4 py-3 last:border-b-0">
            <div className="mb-1 flex items-baseline gap-2">
                <span className="text-label font-semibold tracking-[0.05em] text-faint uppercase">
                    Prior observation
                </span>
                <span className="numeric-mono text-mono text-faint">{observedOn}</span>
            </div>
            <p className="text-ui text-muted">{summary}</p>
        </div>
    );
}
