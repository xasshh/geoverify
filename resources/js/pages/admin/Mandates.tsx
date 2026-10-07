import { useEffect } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { ConsoleShell } from '@/components/ConsoleShell';
import { SelectField, TextField } from '@/components/Field';

interface Mandate {
    id: number;
    name: string;
    client: string;
    lgaCode: string | null;
    boundarySource: string;
    contractRef: string | null;
    resolution: number;
    areaKm2: number;
    cells: number;
    footprints: number;
    imagery: {
        status: string;
        progress: number;
        stage: string | null;
        error: string | null;
        captured: string | null;
        cloudPct: number | null;
        megabytes: number | null;
        maxZoom: number | null;
    } | null;
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
    const page = usePage();
    const flash = page.props.flash.status;
    // A refused imagery request (already building, too large) comes back as
    // an error on the page rather than on a form.
    const imageryError = page.props.errors.imagery;
    const form = useForm({
        lgaCode: lgas[0]?.code ?? '',
        client: '',
        name: '',
        contractRef: '',
        resolution: 9,
    });
    const upload = useForm<{
        boundary: File | null;
        client: string;
        name: string;
        contractRef: string;
        resolution: number;
    }>({
        boundary: null,
        client: '',
        name: '',
        contractRef: '',
        // Larger cells for land: a forest block is walked, not knocked on.
        resolution: 8,
    });

    // While imagery is being built, look again every few seconds. Only the
    // mandates prop is reloaded, and only while something is working.
    const building = mandates.some((m) => m.imagery?.status === 'queued' || m.imagery?.status === 'processing');

    useEffect(() => {
        if (!building) {
            return;
        }

        const timer = window.setInterval(() => {
            router.reload({ only: ['mandates'] });
        }, 5000);

        return () => {
            window.clearInterval(timer);
        };
    }, [building]);

    return (
        <ConsoleShell current="mandates">
            <Head title="Mandates" />

            <div className="mx-auto max-w-[1100px] px-6 pb-20">
                <header className="mt-8 border-b border-rule pb-3">
                    <p className="text-label font-semibold tracking-[0.05em] text-gold uppercase">
                        In house
                    </p>
                    <h1 className="font-display text-display-m text-ink">Mandates</h1>
                </header>

                {flash !== null && (
                    <p className="rounded-sm bg-green-soft mt-4 px-4 py-2.5 text-ui text-ink">
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

                    <div className="flex flex-wrap items-end gap-3 rounded-card border border-rule p-4 bg-raised">
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

                    <p className="rounded-sm bg-amber-soft mt-3 max-w-[68ch] px-3 py-2 text-ui text-muted">
                        Two steps still need the server: ingesting building footprints, which is
                        the denominator every completion figure is measured against, and building
                        the offline map pack. Both read files too large to upload through a
                        browser. Until footprints are ingested a mandate has cells but no work
                        list.
                    </p>
                </section>

                <section className="mt-10">
                    <h2 className="font-display text-display-s text-ink">Ground from a boundary file</h2>
                    <p className="mt-1 mb-3 max-w-[68ch] text-ui text-muted">
                        For ground an LGA does not describe: a forest reserve, a project site, a group of villages.
                        Send GeoJSON, KML, a zipped Shapefile or a GeoPackage; every area in it becomes one mandate.
                        The file is kept, so the ground can always be traced to what the client sent.
                    </p>
                    <div className="flex flex-wrap items-end gap-3 rounded-card border border-rule p-4 bg-raised">
                        <label className="flex flex-col gap-1 text-label font-semibold tracking-[0.05em] text-muted uppercase">
                            Boundary file
                            <input
                                type="file"
                                accept=".geojson,.json,.kml,.zip,.gpkg"
                                onChange={(e) => {
                                    upload.setData('boundary', e.target.files?.[0] ?? null);
                                }}
                                className="h-11 text-ui font-normal tracking-normal normal-case text-ink file:mr-3 file:rounded-sm file:border file:border-rule-strong file:bg-raised file:px-3 file:py-2 file:text-ui"
                            />
                        </label>
                        <TextField
                            label="Client"
                            value={upload.data.client}
                            onChange={(e) => {
                                upload.setData('client', e.target.value);
                            }}
                        />
                        <TextField
                            label="Mandate name"
                            placeholder="Guma forest block"
                            value={upload.data.name}
                            onChange={(e) => {
                                upload.setData('name', e.target.value);
                            }}
                        />
                        <TextField
                            label="Contract ref"
                            value={upload.data.contractRef}
                            onChange={(e) => {
                                upload.setData('contractRef', e.target.value);
                            }}
                        />
                        <SelectField
                            label="H3 resolution"
                            value={String(upload.data.resolution)}
                            onChange={(e) => {
                                upload.setData('resolution', Number(e.target.value));
                            }}
                        >
                            <option value="7">7, about 5 km2 a cell</option>
                            <option value="8">8, about 0.7 km2 (land)</option>
                            <option value="9">9, about 0.1 km2 (towns)</option>
                        </SelectField>
                        <Button
                            variant="primary"
                            busy={upload.processing}
                            disabled={upload.data.boundary === null || upload.data.client === '' || upload.data.name === ''}
                            onClick={() => {
                                upload.post('/admin/mandates/from-boundary', {
                                    forceFormData: true,
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        upload.reset();
                                    },
                                });
                            }}
                        >
                            Create and tile
                        </Button>
                    </div>
                    {Object.entries(upload.errors).map(([key, error]) => (
                        <p key={key} className="mt-2 text-label text-alert">
                            {error}
                        </p>
                    ))}
                </section>

                <section className="mt-10">
                    <h2 className="font-display text-display-s text-ink">Under contract</h2>
                    {imageryError !== undefined && <p className="mt-2 text-label text-alert">{imageryError}</p>}
                    <ul className="mt-3 flex flex-col rounded-card border border-rule px-4 bg-raised">
                        {mandates.map((mandate) => (
                            <li
                                key={mandate.id}
                                className="flex flex-wrap items-baseline gap-x-6 gap-y-1 border-b border-rule py-3 last:border-b-0"
                            >
                                <div className="min-w-[200px] flex-1">
                                    <p className="text-ui text-ink">{mandate.name}</p>
                                    <p className="numeric-mono text-label text-faint">
                                        {mandate.client} &middot;{' '}
                                        {mandate.boundarySource === 'uploaded' ? 'from a boundary file' : mandate.lgaCode}
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
                                            ? 'numeric-mono w-[190px] text-label text-amber-ink'
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
                                <ImageryCell mandate={mandate} />
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

/**
 * Satellite imagery for one mandate: build it, watch it build, or see what is
 * current. Built from free Sentinel-2 images, so there is nothing to upload.
 */
function ImageryCell({ mandate }: { mandate: Mandate }) {
    const image = mandate.imagery;
    const working = image !== null && (image.status === 'queued' || image.status === 'processing');

    const build = (
        <button
            type="button"
            onClick={() => {
                router.post(`/admin/mandates/${String(mandate.id)}/imagery`, {}, { preserveScroll: true });
            }}
            className="text-label text-gold underline underline-offset-2"
        >
            {image === null ? 'Build satellite view' : 'Rebuild satellite view'}
        </button>
    );

    return (
        <div className="basis-full pt-1 text-label">
            {image === null && build}
            {working && (
                <span className="flex items-center gap-2 text-muted">
                    <progress className="h-1.5 w-32 accent-gold" value={image.progress} max={100} />
                    {image.stage ?? 'Waiting to start'}
                </span>
            )}
            {image?.status === 'ready' && (
                <span className="flex flex-wrap items-baseline gap-x-4 gap-y-1 text-muted">
                    <span>
                        Satellite view of {image.captured ?? 'unknown date'}
                        {image.cloudPct !== null && `, ${String(image.cloudPct)}% cloud`}
                    </span>
                    <span className="numeric-mono text-faint">
                        {image.megabytes !== null && `${image.megabytes.toFixed(1)} MB`} &middot; to zoom {image.maxZoom ?? '?'}
                    </span>
                    {build}
                </span>
            )}
            {image?.status === 'failed' && (
                <span className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                    <span className="text-alert">Satellite view failed: {image.error ?? 'no reason given'}</span>
                    {build}
                </span>
            )}
        </div>
    );
}
