import { useState } from 'react';
import type { SubmitEventHandler } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { AppBar } from '@/components/AppBar';
import { Button } from '@/components/Button';
import { DataTable, type Column } from '@/components/DataTable';
import { SelectField, TextField } from '@/components/Field';
import { StatusPill } from '@/components/StatusPill';
import { cx } from '@/lib/cx';
import type { StatusTone } from '@/lib/status';

interface Officer {
    id: number;
    name: string;
    staffRef: string | null;
    open: number;
    overdue: number;
    footprints: number;
}

interface Row {
    id: number;
    officer: string;
    staffRef: string | null;
    h3: string;
    footprints: number;
    captured: number;
    status: string;
    statusLabel: string;
    dueOn: string | null;
    overdue: boolean;
    assignedAt: string | null;
}

interface AssignmentsProps {
    area: { id: number; name: string; client: string };
    officers: Officer[];
    assignments: Row[];
    unassignedCount: number;
}

const TONE: Record<string, StatusTone> = {
    assigned: 'idle',
    in_progress: 'progress',
    submitted: 'progress',
    accepted: 'accepted',
    returned: 'review',
    reassigned: 'idle',
};

export default function Assignments({
    area,
    officers,
    assignments,
    unassignedCount,
}: AssignmentsProps) {
    const flash = usePage().props.flash;
    const [releasing, setReleasing] = useState<number | null>(null);

    const { data, setData, post, processing, errors } = useForm({
        officer_id: officers[0]?.id.toString() ?? '',
        count: '20',
        due_on: '',
    });

    const submit: SubmitEventHandler<HTMLFormElement> = (event) => {
        event.preventDefault();
        post(`/console/coverage/${String(area.id)}/assignments`, { preserveScroll: true });
    };

    const release = (id: number) => {
        setReleasing(id);
        router.delete(`/console/assignments/${String(id)}`, {
            preserveScroll: true,
            onFinish: () => {
                setReleasing(null);
            },
        });
    };

    const columns: ReadonlyArray<Column<Row>> = [
        {
            key: 'h3',
            header: 'Cell',
            render: (row) => <span className="numeric-mono text-mono">{row.h3}</span>,
        },
        {
            key: 'officer',
            header: 'Officer',
            render: (row) => (
                <span>
                    {row.officer}
                    {row.staffRef !== null && (
                        <span className="ml-2 numeric-mono text-label text-faint">{row.staffRef}</span>
                    )}
                </span>
            ),
        },
        { key: 'footprints', header: 'Detected', numeric: true, render: (row) => row.footprints },
        { key: 'captured', header: 'Captured', numeric: true, render: (row) => row.captured },
        {
            key: 'due',
            header: 'Due',
            render: (row) =>
                row.dueOn === null ? (
                    <span className="text-faint">No date</span>
                ) : (
                    <span className={cx('numeric-mono text-mono', row.overdue && 'text-amber')}>
                        {row.dueOn}
                        {row.overdue && ' overdue'}
                    </span>
                ),
        },
        {
            key: 'status',
            header: 'Status',
            render: (row) => (
                <StatusPill tone={TONE[row.status] ?? 'idle'} label={row.statusLabel} size="sm" />
            ),
        },
        {
            key: 'actions',
            header: 'Action',
            render: (row) => (
                <Button
                    variant="quiet"
                    busy={releasing === row.id}
                    onClick={() => {
                        release(row.id);
                    }}
                >
                    Release
                </Button>
            ),
        },
    ];

    return (
        <div data-mode="daylight" className="min-h-dvh bg-surface text-ink">
            <Head title={`Assignments: ${area.name}`} />
            <AppBar
                variant="console"
                links={[
                    { label: 'Coverage', href: '/console/coverage', current: false },
                    { label: 'Assignments', href: `/console/coverage/${String(area.id)}/assignments`, current: true },
                    { label: 'Review', href: '/console/review', current: false },
                    { label: 'Live', href: '/console/live', current: false },
                    { label: 'Exports', href: '/console/exports', current: false },
                ]}
            />

            <div className="mx-auto max-w-7xl px-6 pb-20">
                <header className="mt-8 flex flex-wrap items-baseline justify-between gap-4 border-b-[1.5px] border-ink pb-3">
                    <div>
                        <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
                            Assignments
                        </p>
                        <h1 className="font-display text-display-m text-ink">{area.name}</h1>
                    </div>
                    <p className="numeric-mono text-mono text-muted">
                        {area.client} / {unassignedCount.toLocaleString()} cells unassigned
                    </p>
                </header>

                {flash.status !== null && (
                    <p className="mt-4 border-l-2 border-green bg-raised px-4 py-2.5 text-ui text-ink">
                        {flash.status}
                    </p>
                )}

                <div className="mt-8 grid gap-8 lg:grid-cols-[320px_1fr]">
                    <aside className="flex flex-col gap-6">
                        <form
                            onSubmit={submit}
                            className="flex flex-col gap-4 rounded-sm border border-rule-strong p-4"
                        >
                            <h2 className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                                Hand out work
                            </h2>

                            <SelectField
                                label="Officer"
                                value={data.officer_id}
                                onChange={(e) => {
                                    setData('officer_id', e.target.value);
                                }}
                                {...(errors.officer_id === undefined ? {} : { error: errors.officer_id })}
                            >
                                {officers.map((o) => (
                                    <option key={o.id} value={o.id}>
                                        {o.name} ({o.open} open)
                                    </option>
                                ))}
                            </SelectField>

                            <TextField
                                label="How many cells"
                                type="number"
                                min={1}
                                max={500}
                                value={data.count}
                                onChange={(e) => {
                                    setData('count', e.target.value);
                                }}
                                hint="Busiest unassigned cells first, by detected buildings."
                                {...(errors.count === undefined ? {} : { error: errors.count })}
                            />

                            <TextField
                                label="Due on"
                                type="date"
                                value={data.due_on}
                                onChange={(e) => {
                                    setData('due_on', e.target.value);
                                }}
                                {...(errors.due_on === undefined ? {} : { error: errors.due_on })}
                            />

                            <Button type="submit" variant="primary" busy={processing} fullWidth>
                                Assign cells
                            </Button>
                        </form>

                        <div className="rounded-sm border border-rule-strong">
                            <h2 className="border-b border-rule px-4 py-2.5 text-label font-semibold tracking-[0.12em] text-muted uppercase">
                                Workload
                            </h2>
                            <ul>
                                {officers.map((o) => (
                                    <li
                                        key={o.id}
                                        className="flex items-baseline justify-between gap-3 border-b border-rule px-4 py-2.5 last:border-b-0"
                                    >
                                        <span className="min-w-0">
                                            <span className="block truncate text-ui text-ink">{o.name}</span>
                                            <span className="numeric-mono text-label text-faint">
                                                {o.footprints.toLocaleString()} buildings to visit
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-right">
                                            <span className="numeric-mono text-ui text-ink">{o.open}</span>
                                            {o.overdue > 0 && (
                                                <span className="ml-2 numeric-mono text-label text-amber">
                                                    {o.overdue} late
                                                </span>
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </aside>

                    <DataTable
                        columns={columns}
                        rows={assignments}
                        rowKey={(row) => row.id.toString()}
                        caption="Open assignments in this mandate"
                        empty={
                            <>
                                <span className="text-ink">Nobody is holding ground here yet.</span>
                                <br />
                                Assign cells to an officer to start.
                            </>
                        }
                    />
                </div>
            </div>
        </div>
    );
}
