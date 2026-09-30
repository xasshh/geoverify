import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/Button';
import { EnumerateShell } from '@/components/EnumerateShell';
import { ProjectTile, type ProjectCard } from '@/components/OrganisationParts';
import type { EnumerateFrame } from '@/lib/enumerate';

interface Props {
    frame: EnumerateFrame;
    projects: ProjectCard[];
    fieldTypes: string[];
    start: boolean;
}

const TYPE_LABEL: Record<string, string> = { text: 'Text', number: 'Number', list: 'List', yes_no: 'Yes / no', photo: 'Photo', date: 'Date' };
const INPUT = 'h-11 rounded-sm border border-rule-strong bg-raised px-3 text-ui font-normal text-ink focus:border-gold focus:outline-none';

/**
 * Enumeration projects: the organisation's commissions, and a new one. What
 * is asked for here is a brief for the account manager, who scopes it and runs
 * it as a campaign; the project page then shows it live.
 */
export default function Projects({ frame, projects, fieldTypes, start }: Props) {
    const can = frame.organisation?.can.project === true;
    const [open, setOpen] = useState(start && can);
    const form = useForm({
        name: '',
        subject: '',
        area: '',
        target_records: '',
        wanted_by: '',
        notes: '',
        fields: [
            { label: 'Business name', type: 'text' },
            { label: '', type: 'text' },
        ],
    });

    const setField = (i: number, key: 'label' | 'type', value: string) => {
        form.setData('fields', form.data.fields.map((f, j) => (j === i ? { ...f, [key]: value } : f)));
    };

    return (
        <EnumerateShell
            current="projects"
            frame={frame}
            title="Enumeration projects"
            actions={
                can && !open ? (
                    <Button variant="primary" size="field" onClick={() => { setOpen(true); }}>
                        + New project
                    </Button>
                ) : undefined
            }
        >
            <Head title="Enumeration projects" />

            {open && (
                <form
                    className="mb-6 grid gap-4 rounded-card border border-rule bg-raised px-6 py-6 lg:grid-cols-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/enumerate/organisation/projects');
                    }}
                >
                    <h2 className="text-body font-extrabold text-ink lg:col-span-2">New enumeration project</h2>
                    <label className="flex flex-col gap-1.5 text-table font-bold text-ink">
                        Project name
                        <input className={INPUT} value={form.data.name} onChange={(e) => { form.setData('name', e.target.value); }} placeholder="Vendor onboarding survey, Lagos" />
                    </label>
                    <label className="flex flex-col gap-1.5 text-table font-bold text-ink">
                        What is being counted
                        <input className={INPUT} value={form.data.subject} onChange={(e) => { form.setData('subject', e.target.value); }} placeholder="Vendors, schools, boreholes" />
                    </label>
                    <label className="flex flex-col gap-1.5 text-table font-bold text-ink lg:col-span-2">
                        Where
                        <input className={INPUT} value={form.data.area} onChange={(e) => { form.setData('area', e.target.value); }} placeholder="Kosofe and Ikeja LGAs, Lagos" />
                    </label>
                    <label className="flex flex-col gap-1.5 text-table font-bold text-ink">
                        About how many records
                        <input className={INPUT} inputMode="numeric" value={form.data.target_records} onChange={(e) => { form.setData('target_records', e.target.value.replace(/\D/g, '')); }} placeholder="1200" />
                    </label>
                    <label className="flex flex-col gap-1.5 text-table font-bold text-ink">
                        Wanted by
                        <input className={INPUT} type="date" value={form.data.wanted_by} onChange={(e) => { form.setData('wanted_by', e.target.value); }} />
                    </label>

                    <fieldset className="lg:col-span-2">
                        <legend className="text-table font-bold text-ink">Your data fields</legend>
                        <ul className="mt-2 flex flex-col gap-2">
                            {form.data.fields.map((f, i) => (
                                <li key={i} className="flex gap-2">
                                    <input className={`${INPUT} min-w-0 flex-1`} value={f.label} aria-label={`Field ${String(i + 1)}`} onChange={(e) => { setField(i, 'label', e.target.value); }} placeholder="e.g. Staff on site" />
                                    <select className={INPUT} value={f.type} aria-label={`Field ${String(i + 1)} type`} onChange={(e) => { setField(i, 'type', e.target.value); }}>
                                        {fieldTypes.map((t) => (
                                            <option key={t} value={t}>
                                                {TYPE_LABEL[t] ?? t}
                                            </option>
                                        ))}
                                    </select>
                                </li>
                            ))}
                        </ul>
                        <button
                            type="button"
                            onClick={() => { form.setData('fields', [...form.data.fields, { label: '', type: 'text' }]); }}
                            className="mt-2 text-table font-extrabold text-gold hover:text-gold-dark"
                        >
                            + Add a field
                        </button>
                        <p className="mt-1 text-[0.75rem] text-muted">Every record also gets its position, a photograph and the officer who took it.</p>
                    </fieldset>

                    <label className="flex flex-col gap-1.5 text-table font-bold text-ink lg:col-span-2">
                        Anything else we should know
                        <textarea rows={3} className="rounded-sm border border-rule-strong bg-raised p-3 text-ui font-normal" value={form.data.notes} onChange={(e) => { form.setData('notes', e.target.value); }} />
                    </label>

                    {form.errors.name !== undefined && <p role="alert" className="text-ui font-semibold text-alert-ink lg:col-span-2">{form.errors.name}</p>}
                    <div className="flex gap-2 lg:col-span-2">
                        <Button type="submit" variant="primary" size="field" busy={form.processing}>
                            Request the project
                        </Button>
                        <Button variant="quiet" size="field" onClick={() => { setOpen(false); }}>
                            Cancel
                        </Button>
                    </div>
                </form>
            )}

            {!can && frame.organisation?.status !== 'approved' && (
                <p className="mb-5 max-w-none rounded-sm bg-sunken px-4 py-3 text-ui text-muted">Projects open once we approve the organisation.</p>
            )}

            {projects.length === 0 ? (
                <p className="max-w-none rounded-card border border-dashed border-rule-strong bg-raised px-6 py-10 text-center text-ui text-muted">No projects yet.</p>
            ) : (
                <div className="grid gap-4 md:grid-cols-2">
                    {projects.map((p) => <ProjectTile key={p.reference} project={p} />)}
                </div>
            )}
        </EnumerateShell>
    );
}
