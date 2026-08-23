import { useMemo, useState } from 'react';
import { Head } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { CoverageBar, FootprintLegend, MapChrome } from '@/components/MapChrome';
import { PresenceMark } from '@/components/PresenceMark';
import { SectorPicker } from '@/components/SectorPicker';
import { PriorObservation, Sheet, SheetSection } from '@/components/Sheet';
import { StatusPill } from '@/components/StatusPill';
import { SyncIndicator } from '@/components/SyncIndicator';
import { SelectField, TextField } from '@/components/Field';
import { cx } from '@/lib/cx';
import { useTrace } from '@/lib/geolocation';
import type { TracePoint } from '@/components/PresenceMark';

interface Option {
    value: string;
    label: string;
    expectsFootprint?: boolean;
    expectsEnterprises?: boolean;
}

interface Cell {
    id: number;
    h3: string;
    mandate: string;
    footprints: number;
    captured: number;
    centre: [number, number];
}

interface CapturedEnterprise {
    id: number;
    unitLabel: string | null;
    tradingName: string;
    sectorCode: string | null;
}

interface CapturedStructure {
    id: number;
    clientUuid: string;
    structureType: string;
    unitCount: number | null;
    occupancyStatus: string;
    resolvedWard: string | null;
    enterprises: CapturedEnterprise[];
    priorObservation: { observedOn: string; summary: string } | null;
}

interface CaptureProps {
    assignmentId: number;
    cell: Cell;
    structureTypes: Option[];
    occupancyStatuses: Option[];
    structures: CapturedStructure[];
    consentScript: { version: string; text: string };
}

type Stage = 'map' | 'structure' | 'enterprise';

/**
 * The field capture screen.
 *
 * The map is the interface, but a bounded one: the officer is working a single
 * assigned cell, the denominator is on screen at all times, and the primary
 * action sits in the lower third where a thumb reaches it.
 */
export default function Capture({
    assignmentId,
    cell,
    structureTypes,
    occupancyStatuses,
    structures,
    consentScript,
}: CaptureProps) {
    const [stage, setStage] = useState<Stage>('map');
    const [openStructure, setOpenStructure] = useState<CapturedStructure | null>(null);
    const trace = useTrace(true);

    const captured = structures.length;

    // The officer's own path so far, as the mark that ends up on the record.
    const tracePoints = useMemo<TracePoint[]>(
        () => trace.pending.map((f) => [f.longitude, f.latitude] as TracePoint),
        [trace.pending],
    );

    const accuracy = trace.current?.accuracy_m ?? null;
    const poorAccuracy = accuracy !== null && accuracy > 15;

    return (
        <div data-mode="dusk" className="h-dvh bg-surface text-ink">
            <Head title={`Capture ${cell.h3}`} />

            {stage === 'map' && (
                <MapChrome
                    cellId={cell.h3}
                    openFlags={poorAccuracy ? 1 : 0}
                    sync={
                        <SyncIndicator
                            connectivity="online"
                            queued={trace.pending.length}
                            lastSync={null}
                            compact
                        />
                    }
                    coverage={<CoverageBar captured={captured} detected={cell.footprints} />}
                    actions={
                        <div className="flex flex-col gap-2.5">
                            <Button
                                variant="primary"
                                size="field-primary"
                                fullWidth
                                disabled={trace.current === null}
                                onClick={() => {
                                    setOpenStructure(null);
                                    setStage('structure');
                                }}
                            >
                                {trace.current === null ? 'Waiting for a position' : 'Capture this building'}
                            </Button>
                            <div className="flex gap-2.5">
                                <Button variant="secondary" size="field" fullWidth>
                                    Add point
                                </Button>
                                <Button variant="secondary" size="field" fullWidth>
                                    Not a building
                                </Button>
                            </div>
                        </div>
                    }
                >
                    <div className="flex h-full flex-col items-center justify-center gap-4 p-4">
                        {tracePoints.length > 1 ? (
                            <PresenceMark points={tracePoints} size={150} />
                        ) : trace.current === null ? (
                            <div className="text-center">
                                <p className="text-body text-muted">Finding you</p>
                                <p className="mt-1 text-label text-faint">
                                    Stand in the open for a few seconds
                                </p>
                            </div>
                        ) : (
                            <div className="text-center">
                                <p className="text-body text-ink">Position locked</p>
                                <p className="mt-1 text-label text-faint">
                                    Your track appears here as you walk
                                </p>
                            </div>
                        )}

                        <FootprintLegend className="justify-center" />

                        {accuracy !== null && (
                            <p
                                className={cx(
                                    'numeric-mono text-mono',
                                    poorAccuracy ? 'text-amber' : 'text-faint',
                                )}
                            >
                                {trace.current?.latitude.toFixed(5) ?? ''}, {trace.current?.longitude.toFixed(5) ?? ''}
                                {'  '}
                                +/- {accuracy.toFixed(1)} m
                            </p>
                        )}

                        {poorAccuracy && (
                            <p className="max-w-[36ch] text-center text-ui text-amber">
                                Accuracy is poor here. Move away from the wall or into the open
                                before capturing.
                            </p>
                        )}

                        {trace.wakeLock === 'denied' && (
                            <p className="max-w-[36ch] text-center text-ui text-amber">
                                The screen may switch itself off. If it does, your trace stops
                                recording, so keep the app open.
                            </p>
                        )}

                        {trace.error !== null && (
                            <p className="max-w-[36ch] text-center text-ui text-alert">{trace.error}</p>
                        )}
                    </div>
                </MapChrome>
            )}

            {stage === 'structure' && (
                <StructureSheet
                    assignmentId={assignmentId}
                    structureTypes={structureTypes}
                    occupancyStatuses={occupancyStatuses}
                    existing={openStructure}
                    position={trace.current}
                    consentScript={consentScript}
                    onClose={() => {
                        setStage('map');
                    }}
                    onCaptureEnterprise={(structure) => {
                        setOpenStructure(structure);
                        setStage('enterprise');
                    }}
                />
            )}

            {stage === 'enterprise' && openStructure !== null && (
                <EnterpriseSheet
                    structure={openStructure}
                    onClose={() => {
                        setStage('structure');
                    }}
                />
            )}
        </div>
    );
}

interface StructureSheetProps {
    assignmentId: number;
    structureTypes: Option[];
    occupancyStatuses: Option[];
    existing: CapturedStructure | null;
    position: { latitude: number; longitude: number; accuracy_m: number | null } | null;
    consentScript: { version: string; text: string };
    onClose: () => void;
    onCaptureEnterprise: (structure: CapturedStructure) => void;
}

/**
 * The structure sheet: an observation form, not an edit form.
 *
 * A revisit records what is true today. The previous visit sits below, greyed
 * and not editable, so the officer sees what changed since March without being
 * able to rewrite March.
 */
function StructureSheet({
    structureTypes,
    occupancyStatuses,
    existing,
    position,
    consentScript,
    onClose,
    onCaptureEnterprise,
}: StructureSheetProps) {
    const [type, setType] = useState(existing?.structureType ?? 'shophouse');
    const [occupancy, setOccupancy] = useState(existing?.occupancyStatus ?? 'occupied');
    const [unitCount, setUnitCount] = useState(existing?.unitCount?.toString() ?? '1');
    const [consentGiven, setConsentGiven] = useState(false);
    const [showScript, setShowScript] = useState(false);

    const units = Number.parseInt(unitCount, 10);
    const enterprises = existing?.enterprises ?? [];
    const expectsEnterprises =
        occupancyStatuses.find((o) => o.value === occupancy)?.expectsEnterprises ?? false;

    return (
        <div className="flex h-full flex-col">
            <div className="shrink-0 border-b border-rule px-4 py-2">
                <button
                    type="button"
                    onClick={onClose}
                    className="min-h-touch text-ui text-muted underline underline-offset-2"
                >
                    Back to the map
                </button>
            </div>

            <div className="min-h-0 flex-1">
                <Sheet
                    title={existing === null ? 'New structure' : 'Structure'}
                    meta={
                        position === null
                            ? 'No position yet'
                            : `${position.latitude.toFixed(5)}, ${position.longitude.toFixed(5)}   +/- ${(position.accuracy_m ?? 0).toFixed(1)} m`
                    }
                    footer={
                        <Button
                            variant="primary"
                            size="field-primary"
                            fullWidth
                            disabled={!consentGiven && expectsEnterprises}
                        >
                            Save capture
                        </Button>
                    }
                    footerNote="Saved on device. Syncs when there is signal."
                >
                    <SheetSection label="What is it">
                        <div className="flex flex-col gap-4">
                            <SelectField
                                label="Type"
                                size="field"
                                value={type}
                                onChange={(e) => {
                                    setType(e.target.value);
                                }}
                            >
                                {structureTypes.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </SelectField>

                            <SelectField
                                label="What did you find"
                                size="field"
                                value={occupancy}
                                onChange={(e) => {
                                    setOccupancy(e.target.value);
                                }}
                            >
                                {occupancyStatuses.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </SelectField>

                            {expectsEnterprises && (
                                <TextField
                                    label="Units in this building"
                                    size="field"
                                    type="number"
                                    min={1}
                                    max={500}
                                    value={unitCount}
                                    onChange={(e) => {
                                        setUnitCount(e.target.value);
                                    }}
                                    hint="Count the shutters, not the businesses. This becomes the checklist."
                                />
                            )}
                        </div>
                    </SheetSection>

                    {expectsEnterprises && (
                        <SheetSection
                            label="Businesses"
                            trailing={`${String(enterprises.length)} / ${String(Number.isNaN(units) ? 0 : units)}`}
                        >
                            {existing === null && (
                                <p className="mb-3 border-l-2 border-gold pl-3 text-ui text-muted">
                                    Save the building first, then add each business to it.
                                </p>
                            )}

                            <ul className="flex flex-col gap-2">
                                {Array.from({ length: Number.isNaN(units) ? 0 : Math.min(units, 40) }).map(
                                    (_, index) => {
                                        const done = enterprises[index];
                                        const label = done?.unitLabel ?? `Unit ${String(index + 1)}`;

                                        return (
                                            <li
                                                key={label}
                                                className="flex items-center justify-between gap-3 rounded-sm border border-rule px-3 py-2"
                                            >
                                                <span className="min-w-0">
                                                    <span className="block truncate text-ui text-ink">
                                                        <span className="numeric-mono text-label text-faint">
                                                            {label}
                                                        </span>{' '}
                                                        {done?.tradingName ?? ''}
                                                    </span>
                                                    <span className="text-label text-faint">
                                                        {done === undefined
                                                            ? 'Not captured'
                                                            : (done.sectorCode ?? 'No sector')}
                                                    </span>
                                                </span>
                                                {done === undefined ? (
                                                    <Button
                                                        variant="secondary"
                                                        size="field-compact"
                                                        // A business cannot attach to a
                                                        // building that has no record yet.
                                                        // Disabled and explained beats a
                                                        // control that does nothing.
                                                        disabled={existing === null}
                                                        onClick={() => {
                                                            if (existing !== null) {
                                                                onCaptureEnterprise(existing);
                                                            }
                                                        }}
                                                    >
                                                        Add
                                                    </Button>
                                                ) : (
                                                    <StatusPill tone="accepted" label="Done" size="sm" />
                                                )}
                                            </li>
                                        );
                                    },
                                )}
                            </ul>
                        </SheetSection>
                    )}

                    {/*
                        NDPA consent, recorded where the law requires it: at the point
                        of collection, by the officer who read the script out.
                    */}
                    {expectsEnterprises && (
                        <SheetSection label="Consent">
                            <div className="flex flex-col gap-3">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setShowScript((v) => !v);
                                    }}
                                    className="min-h-touch text-left text-ui text-gold underline underline-offset-2"
                                >
                                    {showScript ? 'Hide the script' : 'Read the script out'}
                                </button>

                                {showScript && (
                                    <p className="rounded-sm border border-rule bg-raised p-3 text-body text-ink">
                                        {consentScript.text}
                                    </p>
                                )}

                                <label className="flex min-h-touch-lg items-center gap-3 text-body text-ink">
                                    <input
                                        type="checkbox"
                                        checked={consentGiven}
                                        onChange={(e) => {
                                            setConsentGiven(e.target.checked);
                                        }}
                                        className="size-6 rounded-[2px] border-rule-strong accent-gold"
                                    />
                                    I read this out and they agreed
                                </label>

                                <p className="numeric-mono text-label text-faint">
                                    Script {consentScript.version}
                                </p>
                            </div>
                        </SheetSection>
                    )}

                    {existing?.priorObservation != null && (
                        <PriorObservation
                            observedOn={existing.priorObservation.observedOn}
                            summary={existing.priorObservation.summary}
                        />
                    )}
                </Sheet>
            </div>
        </div>
    );
}

/** One business, inside a structure the officer already recorded. */
function EnterpriseSheet({
    structure,
    onClose,
}: {
    structure: CapturedStructure;
    onClose: () => void;
}) {
    const [tradingName, setTradingName] = useState('');
    const [sector, setSector] = useState<{
        code: string;
        name: string;
        matchedOn: string;
        isAlias: boolean;
    } | null>(null);
    const [scale, setScale] = useState('micro');
    const [signage, setSignage] = useState(false);

    return (
        <div className="flex h-full flex-col">
            <div className="shrink-0 border-b border-rule px-4 py-2">
                <button
                    type="button"
                    onClick={onClose}
                    className="min-h-touch text-ui text-muted underline underline-offset-2"
                >
                    Back to the building
                </button>
            </div>

            <div className="min-h-0 flex-1">
                <Sheet
                    title="Business"
                    meta={`Unit ${String(structure.enterprises.length + 1)} of ${String(structure.unitCount ?? 1)}`}
                    footer={
                        <Button
                            variant="primary"
                            size="field-primary"
                            fullWidth
                            disabled={tradingName.trim() === '' || sector === null}
                        >
                            Save business
                        </Button>
                    }
                    footerNote="Saved on device. Syncs when there is signal."
                >
                    <SheetSection label="Who are they">
                        <div className="flex flex-col gap-4">
                            <TextField
                                label="Trading name"
                                size="field"
                                value={tradingName}
                                onChange={(e) => {
                                    setTradingName(e.target.value);
                                }}
                                hint="The name on the sign, exactly as written."
                            />

                            <SectorPicker value={sector} onChange={setSector} />

                            <SelectField
                                label="Size"
                                size="field"
                                value={scale}
                                onChange={(e) => {
                                    setScale(e.target.value);
                                }}
                            >
                                <option value="micro">Micro, 1 to 9 people</option>
                                <option value="small">Small, 10 to 49</option>
                                <option value="medium">Medium, 50 to 199</option>
                                <option value="large">Large, 200 and above</option>
                            </SelectField>

                            <label className="flex min-h-touch-lg items-center gap-3 text-body text-ink">
                                <input
                                    type="checkbox"
                                    checked={signage}
                                    onChange={(e) => {
                                        setSignage(e.target.checked);
                                    }}
                                    className="size-6 rounded-[2px] border-rule-strong accent-gold"
                                />
                                There is a sign I can photograph
                            </label>
                        </div>
                    </SheetSection>
                </Sheet>
            </div>
        </div>
    );
}
