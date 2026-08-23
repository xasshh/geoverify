import { Head, Link } from '@inertiajs/react';
import { AppBar } from '@/components/AppBar';
import { DataTable, type Column } from '@/components/DataTable';

interface Area {
    id: number;
    name: string;
    client: string;
    contractRef: string | null;
    lgaCode: string | null;
    status: string;
    cells: number;
}

export default function CoverageIndex({ areas }: { areas: Area[] }) {
    const columns: ReadonlyArray<Column<Area>> = [
        {
            key: 'name',
            header: 'Mandate',
            render: (a) => (
                <Link href={`/console/coverage/${String(a.id)}`} className="text-ink underline-offset-2 hover:underline">
                    {a.name}
                </Link>
            ),
        },
        { key: 'client', header: 'Client', render: (a) => a.client },
        {
            key: 'ref',
            header: 'Contract',
            render: (a) => <span className="numeric-mono text-mono text-muted">{a.contractRef ?? '.'}</span>,
        },
        {
            key: 'lga',
            header: 'LGA',
            render: (a) => <span className="numeric-mono text-mono text-muted">{a.lgaCode ?? '.'}</span>,
        },
        { key: 'cells', header: 'Cells', numeric: true, render: (a) => a.cells.toLocaleString() },
        {
            key: 'work',
            header: '',
            render: (a) => (
                <Link
                    href={`/console/coverage/${String(a.id)}/assignments`}
                    className="text-gold underline-offset-2 hover:underline"
                >
                    Assignments
                </Link>
            ),
        },
    ];

    return (
        <div data-mode="daylight" className="min-h-dvh bg-surface text-ink">
            <Head title="Coverage" />
            <AppBar variant="console" links={[{ label: 'Coverage', href: '/console/coverage', current: true }]} />
            <div className="mx-auto max-w-6xl px-6 pb-20">
                <header className="mt-8 border-b-[1.5px] border-ink pb-3">
                    <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">Console</p>
                    <h1 className="font-display text-display-m text-ink">Coverage</h1>
                </header>
                <div className="mt-8">
                    <DataTable
                        columns={columns}
                        rows={areas}
                        rowKey={(a) => a.id.toString()}
                        caption="Mandates"
                        empty={<span className="text-ink">No mandates yet.</span>}
                    />
                </div>
            </div>
        </div>
    );
}
