import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ConsoleShell } from '@/components/ConsoleShell';
import { SelectField, TextField } from '@/components/Field';
import { Button } from '@/components/Button';

interface Event_ {
    id: number;
    event: string;
    subject: string;
    subjectType: string;
    subjectId: number;
    actorType: string;
    actorLabel: string | null;
    occurredAt: string;
    evidence: Record<string, unknown> | null;
}

interface Props {
    events: Event_[];
    filters: { event?: string; subjectType?: string; actor?: string; from?: string; to?: string };
    vocabulary: { events: string[]; subjects: string[]; actors: string[] };
    total: number;
    page: number;
    lastPage: number;
    links: { prev: string | null; next: string | null };
}

function at(iso: string): string {
    return new Date(iso).toLocaleString('en-NG', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** The class name is an implementation detail; the reader wants the thing. */
function subjectLabel(name: string): string {
    return name.replace(/([a-z])([A-Z])/g, '$1 $2');
}

/**
 * One row, expandable to the evidence it was recorded with.
 *
 * Collapsed by default because the evidence is a different question from the
 * sequence. Scanning what happened and reading what one entry was based on are
 * two modes, and a log that shows every payload inline supports neither.
 */
function Row({ event }: { event: Event_ }) {
    const [open, setOpen] = useState(false);
    const hasEvidence = event.evidence !== null && Object.keys(event.evidence).length > 0;

    return (
        <li className="border-b border-rule last:border-b-0">
            <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1 py-2">
                <span className="numeric-mono w-[150px] shrink-0 text-label text-faint">
                    {at(event.occurredAt)}
                </span>
                <span className="w-[210px] shrink-0 text-ui text-ink">{event.event}</span>
                <span className="w-[190px] shrink-0 text-label text-muted">
                    {subjectLabel(event.subject)} #{event.subjectId}
                </span>
                <span className="text-label text-muted">
                    {event.actorLabel ?? event.actorType}
                    <span className="text-faint"> &middot; {event.actorType}</span>
                </span>

                {hasEvidence && (
                    <button
                        type="button"
                        onClick={() => {
                            setOpen((v) => !v);
                        }}
                        className="ml-auto text-label text-gold underline underline-offset-2"
                    >
                        {open ? 'Hide' : 'Evidence'}
                    </button>
                )}
            </div>

            {open && hasEvidence && (
                <pre className="mb-2 overflow-x-auto rounded-sm bg-raised px-3 py-2 numeric-mono text-label text-muted">
                    {JSON.stringify(event.evidence, null, 2)}
                </pre>
            )}
        </li>
    );
}

/**
 * The log, read back.
 *
 * verification_events is the thing this business sells, and it had never been
 * visible outside psql. Filtered rather than searched: the questions worth
 * asking are narrow, and a free text search over the evidence column would
 * mostly be an invitation to go looking for a person.
 */
export default function Audit({
    events,
    filters,
    vocabulary,
    total,
    page,
    lastPage,
    links,
}: Props) {
    const [draft, setDraft] = useState({
        event: filters.event ?? '',
        subjectType: filters.subjectType ?? '',
        actor: filters.actor ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
    });

    const apply = () => {
        router.get('/admin/audit', Object.fromEntries(
            Object.entries(draft).filter(([, value]) => value !== ''),
        ), { preserveState: true });
    };

    return (
        <ConsoleShell current="audit">
            <Head title="Audit log" />

            <div className="mx-auto max-w-[1400px] px-6 pb-20">
                <header className="mt-8 flex flex-wrap items-baseline justify-between gap-4 border-b border-rule pb-3">
                    <div>
                        <p className="text-label font-semibold tracking-[0.05em] text-gold uppercase">
                            In house
                        </p>
                        <h1 className="font-display text-display-m text-ink">Audit log</h1>
                    </div>
                    <p className="numeric-mono text-mono text-muted">
                        {total.toLocaleString()} entries
                    </p>
                </header>

                <p className="mt-6 max-w-[72ch] text-ui text-muted">
                    Every capture, decision, claim, sign in, export and consent, appended and never
                    amended. Nothing in this system is hard deleted, so a record that changed state
                    left a line here saying who changed it and what it was based on.
                </p>

                <div className="mt-6 flex flex-wrap items-end gap-3 rounded-card border border-rule p-4 bg-raised">
                    <SelectField
                        label="Event"
                        value={draft.event}
                        onChange={(e) => {
                            setDraft({ ...draft, event: e.target.value });
                        }}
                    >
                        <option value="">Everything</option>
                        {vocabulary.events.map((event) => (
                            <option key={event} value={event}>
                                {event}
                            </option>
                        ))}
                    </SelectField>

                    <SelectField
                        label="Subject"
                        value={draft.subjectType}
                        onChange={(e) => {
                            setDraft({ ...draft, subjectType: e.target.value });
                        }}
                    >
                        <option value="">Anything</option>
                        {vocabulary.subjects.map((subject) => (
                            <option key={subject} value={subject}>
                                {subjectLabel(subject.split('\\').pop() ?? subject)}
                            </option>
                        ))}
                    </SelectField>

                    <SelectField
                        label="Actor"
                        value={draft.actor}
                        onChange={(e) => {
                            setDraft({ ...draft, actor: e.target.value });
                        }}
                    >
                        <option value="">Anyone</option>
                        {vocabulary.actors.map((actor) => (
                            <option key={actor} value={actor}>
                                {actor}
                            </option>
                        ))}
                    </SelectField>

                    <TextField
                        label="From"
                        type="date"
                        value={draft.from}
                        onChange={(e) => {
                            setDraft({ ...draft, from: e.target.value });
                        }}
                    />
                    <TextField
                        label="To"
                        type="date"
                        value={draft.to}
                        onChange={(e) => {
                            setDraft({ ...draft, to: e.target.value });
                        }}
                    />

                    <Button onClick={apply}>Apply</Button>
                </div>

                <ul className="mt-6 flex flex-col rounded-card border border-rule px-4 bg-raised">
                    {events.map((event) => (
                        <Row key={event.id} event={event} />
                    ))}
                    {events.length === 0 && (
                        <li className="py-4 text-ui text-muted">
                            Nothing matches those filters.
                        </li>
                    )}
                </ul>

                <div className="mt-4 flex items-center justify-between">
                    <p className="numeric-mono text-label text-faint">
                        Page {page} of {lastPage}
                    </p>
                    <div className="flex gap-3">
                        {links.prev !== null && (
                            <Link
                                href={links.prev}
                                className="text-ui text-gold underline underline-offset-2"
                            >
                                Newer
                            </Link>
                        )}
                        {links.next !== null && (
                            <Link
                                href={links.next}
                                className="text-ui text-gold underline underline-offset-2"
                            >
                                Older
                            </Link>
                        )}
                    </div>
                </div>
            </div>
        </ConsoleShell>
    );
}
