import { Head } from '@inertiajs/react';
import { ConsoleShell } from '@/components/ConsoleShell';

interface Brief {
    name: string;
    code: string;
    about: string | null;
    objective: string | null;
    startsOn: string | null;
    endsOn: string | null;
    target: number | null;
}

function paragraphs(text: string | null): string[] {
    return (text ?? '').split(/\n\s*\n/).map((p) => p.trim()).filter((p) => p !== '');
}

/** The brief of the campaign this supervisor's cells belong to. */
export default function Brief({ brief }: { brief: Brief | null }) {
    return (
        <ConsoleShell current="brief">
            <Head title="Campaign brief" />
            <div className="mx-auto max-w-3xl px-6 pb-20">
                <header className="mt-8 border-b border-rule pb-3">
                    <h1 className="font-display text-display-l text-ink">Campaign brief</h1>
                </header>
                {brief === null ? (
                    <p className="mt-6 text-ui text-muted">The cells you have assigned are not part of a campaign with a brief.</p>
                ) : (
                    <article className="mt-6 rounded-card border border-rule bg-raised px-6 py-6">
                        <p className="numeric-mono text-table text-muted">{brief.code}</p>
                        <h2 className="mt-1 font-display text-display-m text-ink">{brief.name}</h2>
                        <p className="mt-2 text-ui text-muted">
                            {brief.startsOn ?? 'Start not set'} to {brief.endsOn ?? 'open'}
                            {brief.target !== null && ` · ${brief.target.toLocaleString()} records expected`}
                        </p>
                        {[
                            ['About', brief.about],
                            ['Objective', brief.objective],
                        ].map(([heading, text]) =>
                            paragraphs(text ?? null).length === 0 ? null : (
                                <section key={heading} className="mt-6">
                                    <h3 className="text-label font-extrabold tracking-[0.05em] text-muted uppercase">{heading}</h3>
                                    {paragraphs(text ?? null).map((p) => (
                                        <p key={p} className="mt-2 text-body text-ink">
                                            {p}
                                        </p>
                                    ))}
                                </section>
                            ),
                        )}
                    </article>
                )}
            </div>
        </ConsoleShell>
    );
}
