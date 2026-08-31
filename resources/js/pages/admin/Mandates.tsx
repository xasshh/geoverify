import { Head, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { ConsoleShell } from '@/components/ConsoleShell';
import { SelectField, TextField } from '@/components/Field';

interface Mandate {
    id: number;
    name: string;
    client: string;
    lgaCode: string;
    contractRef: string | null;
    resolution: number;
    areaKm2: number;
    cells: number;
    footprints: number;
}

interface Props {
    mandates: Mandate[];
    lgas: Array<{ code: string; name: string }>;
}

/**
 * Mandates, without a terminal.
 *
 * The two steps that need a person's judgement are here: which ground, and at
 * what resolution. The two that need large files staged on the server are not,
 * and the screen says so plainly rather than letting somebody believe a mandate
 * is ready to work when it has no buildings in it and no offline map.
 */
export default function Mandates({ mandates, lgas }: Props) {
    const flash = usePage().props.flash.status;
    const form = useForm({
        lgaCode: lgas[0]?.code ?? '',
        client: '',
        name: '',
        contractRef: '',
        resolution: 9,
    });

    return (
        <ConsoleShell current="mandates">
            <Head title="Mandates" />

            <div className="mx-auto max-w-[1100px] px-6 pb-20">
                <header className="mt-8 border-b-[1.5px] border-ink pb-3">
                    <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
                        In house
                    </p>
                    <h1 className="font-display text-display-m text-ink">Mandates</h1>
                </header>

                {flash !== null && (
                    <p className="mt-4 border-l-2 border-green bg-raised px-4 py-2.5 text-ui text-ink">
                        {flash}
                    </p>
                )}

                <section className="mt-8">
                    <h2 className="font-display text-display-s text-ink">Contract new ground</h2>
                    <p className="mt-1 mb-3 max-w-[68ch] text-ui text-muted">
                        The boundary is copied from the loaded administrative layer, not referenced
                        against it. If a later data release moves the LGA line, the ground a client
                        contracted for does not move with it. Creating a mandate also tiles it, so
                        it arrives with a work list rather than empty.
                    </p>

                    <div className="flex flex-wrap items-end gap-3 rounded-sm border border-rule-strong p-4">
                        <SelectField
                            label="LGA"
                            value={form.data.lgaCode}
                            onChange={(e) => {
                                form.setData('lgaCode', e.target.value);
                            }}
                        >
                            {lgas.map((lga) => (
                                <option key={lga.code} value={lga.code}>
                                    {lga.name} ({lga.code})
                                </option>
                            ))}
                        </SelectField>

                        <TextField
                            label="Client"
                            value={form.data.client}
                            onChange={(e) => {
                                form.setData('client', e.target.value);
                            }}
                        />
                        <TextField
                            label="Mandate name"
                            placeholder="Defaults to the LGA name"
                            value={form.data.name}
                            onChange={(e) => {
                                form.setData('name', e.target.value);
                            }}
                        />
                        <TextField
                            label="Contract ref"
                            value={form.data.contractRef}
                            onChange={(e) => {
                                form.setData('contractRef', e.target.value);
                            }}
                        />
                        <SelectField
                            label="H3 resolution"
                            value={String(form.data.resolution)}
                            onChange={(e) => {
                                form.setData('resolution', Number(e.target.value));
                            }}
                        >
                            <option value="8">8, coarse</option>
                            <option value="9">9, the default</option>
                            <option value="10">10, fine</option>
                        </SelectField>

                        <Button
                            onClick={() => {
                                form.post('/admin/mandates', { preserveScroll: true });
                            }}
                            disabled={form.processing || form.data.client === ''}
                        >
                            {form.processing ? 'Tiling' : 'Create and tile'}
                        </Button>
                    </div>

                    {Object.values(form.errors).map((error) => (
                        <p key={error} className="mt-2 text-label text-alert">
                            {error}
                        </p>
                    ))}

                    <p className="mt-3 max-w-[68ch] border-l-2 border-amber bg-raised px-3 py-2 text-ui text-muted">
                        Two steps still need the server: ingesting building footprints, which is
                        the denominator every completion figure is measured against, and building
                        the offline map pack. Both read files too large to upload through a
                        browser. Until footprints are ingested a mandate has cells but no work
                        list.
                    </p>
                </section>

                <section className="mt-10">
                    <h2 className="font-display text-display-s text-ink">Under contract</h2>
                    <ul className="mt-3 flex flex-col rounded-sm border border-rule-strong px-4">
                        {mandates.map((mandate) => (
                            <li
                                key={mandate.id}
                                className="flex flex-wrap items-baseline gap-x-6 gap-y-1 border-b border-rule py-3 last:border-b-0"
                            >
                                <div className="min-w-[200px] flex-1">
                                    <p className="text-ui text-ink">{mandate.name}</p>
                                    <p className="numeric-mono text-label text-faint">
                                        {mandate.client} &middot; {mandate.lgaCode}
                                        {mandate.contractRef !== null && ` · ${mandate.contractRef}`}
                                    </p>
                                </div>
                                <span className="numeric-mono w-[110px] text-label text-muted">
                                    {mandate.areaKm2} km2
                                </span>
                                <span className="numeric-mono w-[130px] text-label text-muted">
                                    {mandate.cells.toLocaleString()} cells
                                </span>
                                <span
                                    className={
                                        mandate.footprints === 0
                                            ? 'numeric-mono w-[190px] text-label text-amber'
                                            : 'numeric-mono w-[190px] text-label text-muted'
                                    }
                                >
                                    {mandate.footprints === 0
                                        ? 'no footprints ingested'
                                        : `${mandate.footprints.toLocaleString()} footprints`}
                                </span>
                                <span className="numeric-mono text-label text-faint">
                                    res {mandate.resolution}
                                </span>
                            </li>
                        ))}
                        {mandates.length === 0 && (
                            <li className="py-4 text-ui text-muted">No ground under contract.</li>
                        )}
                    </ul>
                </section>
            </div>
        </ConsoleShell>
    );
}
