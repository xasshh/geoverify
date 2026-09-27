import { FieldShell } from '@/components/FieldShell';
import type { OfficerDay } from '@/lib/fieldDay';

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

/** The campaign brief, as the officer carries it into the field. */
export default function Brief({ day, brief }: { day: OfficerDay; brief: Brief | null }) {
    return (
        <FieldShell day={day} current="brief" title="Campaign brief">
            {brief === null ? (
                <p className="rounded-card border border-rule bg-raised px-6 py-8 text-ui text-muted max-w-none">The cells you hold are not part of a campaign with a brief.</p>
            ) : (
                <article className="max-w-3xl rounded-card border border-rule bg-raised px-6 py-6">
                    <p className="numeric-mono text-table text-muted">{brief.code}</p>
                    <h2 className="mt-1 font-display text-display-m text-ink">{brief.name}</h2>
                    <p className="mt-2 text-ui text-muted">
                        {brief.startsOn ?? 'Start not set'} to {brief.endsOn ?? 'open'}
                        {brief.target !== null && ` · ${brief.target.toLocaleString()} records expected`}
                    </p>
                    {paragraphs(brief.about).length > 0 && (
                        <section className="mt-6">
                            <h3 className="text-label font-extrabold tracking-[0.05em] text-muted uppercase">About</h3>
                            {paragraphs(brief.about).map((p) => (
                                <p key={p} className="mt-2 text-body text-ink">
                                    {p}
                                </p>
                            ))}
                        </section>
                    )}
                    {paragraphs(brief.objective).length > 0 && (
                        <section className="mt-6">
                            <h3 className="text-label font-extrabold tracking-[0.05em] text-muted uppercase">What we need from you</h3>
                            {paragraphs(brief.objective).map((p) => (
                                <p key={p} className="mt-2 text-body text-ink">
                                    {p}
                                </p>
                            ))}
                        </section>
                    )}
                </article>
            )}
        </FieldShell>
    );
}
