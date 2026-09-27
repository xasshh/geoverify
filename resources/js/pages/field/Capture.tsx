import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { CaptureStepHeader, GpsCard, SupervisorNote } from '@/components/CaptureChrome';
import { CoverageBar, FootprintLegend, MapChrome } from '@/components/MapChrome';
import { PresenceMark } from '@/components/PresenceMark';
import { SectorPicker } from '@/components/SectorPicker';
import { PriorObservation, Sheet, SheetSection } from '@/components/Sheet';
import { StatusPill } from '@/components/StatusPill';
import { SyncIndicator } from '@/components/SyncIndicator';
import { SelectField, TextField } from '@/components/Field';
import { cx } from '@/lib/cx';
import { floorOptions } from '@/lib/floors';
import { useTrace } from '@/lib/geolocation';
import { PhotoCapture } from '@/components/PhotoCapture';
import { FieldMap, type CapturedPoint } from '@/components/FieldMap';
import { PackDownload } from '@/components/PackDownload';
import { usePack } from '@/lib/offline/usePack';
import { sendFixes, startSession, uuid7 } from '@/lib/capture';
import { useOfflineQueue } from '@/lib/offline/useOfflineQueue';
import { db } from '@/lib/offline/db';
import type { TracePoint } from '@/components/PresenceMark';

interface Option {
    value: string;
    label: string;
    expectsFootprint?: boolean;
    expectsFloors?: boolean;
    expectsEnterprises?: boolean;
}

interface Cell {
    id: number;
    coverageAreaId: number;
    h3: string;
    mandate: string;
    footprints: number;
    captured: number;
    centre: [number, number];
}

interface CapturedEnterprise {
    id: number;
    unitLabel: string | null;
    /** Ground is 0, a basement is negative. Null where nobody went in. */
    floor: number | null;
    tradingName: string;
    sectorCode: string | null;
}

interface CapturedStructure {
    id: number;
    clientUuid: string;
    structureType: string;
    unitCount: number | null;
    floors: number | null;
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
    const [recorded, setRecorded] = useState<CapturedStructure[]>(structures);
    const [sessionId, setSessionId] = useState<number | null>(null);
    const trace = useTrace(true);
    const { takeFixes } = trace;
    const sessionUuid = useRef(uuid7());
    const queue = useOfflineQueue();
    const pack = usePack(cell.coverageAreaId);

    /**
     * The building the officer tapped on the map.
     *
     * Null is a real answer and stays one: a kiosk between two buildings has no
     * footprint, and the officer must be able to capture it without being made
     * to attach one that is wrong.
     */
    const [selectedFootprint, setSelectedFootprint] = useState<number | null>(null);
    const [street, setStreet] = useState<string | null>(null);
    const [localPoints, setLocalPoints] = useState<CapturedPoint[]>([]);
    const [visitedFootprints, setVisitedFootprints] = useState<number[]>([]);

    const captured = recorded.length;

    // One session per visit to this cell. Guarded by a ref rather than by the
    // dependency list alone: an effect that runs twice would open two sessions
    // and write two session.started events for one visit.
    const sessionRequested = useRef(false);

    useEffect(() => {
        if (sessionRequested.current) {
            return;
        }

        sessionRequested.current = true;

        void startSession({ client_uuid: sessionUuid.current, assignment_id: assignmentId })
            .then((session) => {
                setSessionId(session.id);
            })
            .catch(() => {
                // Capture still works without a session; the trace is what is
                // lost, and the officer is told about that separately.
            });
    }, [assignmentId]);

    /**
     * Sends whatever fixes are waiting.
     *
     * Batched rather than one request per fix: eighty structures a day at one
     * request each would be thousands of requests on a connection that can
     * barely carry the captures. Called on a timer, and again whenever a capture
     * is saved, so an officer who records one building and closes the app does
     * not lose the fixes that put them there.
     */
    const flushFixes = useCallback(() => {
        if (sessionId === null) {
            return;
        }

        const batch = takeFixes();

        if (batch.length === 0) {
            return;
        }

        void sendFixes(sessionId, batch).catch(() => {
            // Kept for M5's queue rather than dropped on the floor.
        });
    }, [sessionId, takeFixes]);

    useEffect(() => {
        const timer = window.setInterval(flushFixes, 20_000);

        return () => {
            window.clearInterval(timer);
        };
    }, [flushFixes]);

    /**
     * What this device has already recorded in this cell.
     *
     * Read from IndexedDB rather than from the page props: a building captured
     * an hour ago with no signal is not in the props, and it must still show as
     * done. Re-read whenever the count changes, which is the only thing that
     * moves it.
     */
    useEffect(() => {
        let live = true;

        void db.structures
            .where('gridCellId')
            .equals(cell.id)
            .toArray()
            .then((rows) => {
                if (!live) {
                    return;
                }

                setLocalPoints(
                    rows.map((row) => ({
                        clientUuid: row.clientUuid,
                        longitude: row.longitude,
                        latitude: row.latitude,
                    })),
                );

                setVisitedFootprints(
                    rows
                        .map((row) => row.externalFootprintId)
                        .filter((id): id is number => id !== null),
                );
            });

        return () => {
            live = false;
        };
    }, [cell.id, recorded.length]);

    // The officer's own path so far, as the mark that ends up on the record.
    const tracePoints = useMemo<TracePoint[]>(() => trace.track, [trace.track]);

    const accuracy = trace.current?.accuracy_m ?? null;
    const poorAccuracy = accuracy !== null && accuracy > 15;

    return (
        <div data-mode="daylight" className="h-dvh bg-surface text-ink">
            <Head title={`Capture ${cell.h3}`} />

            {stage === 'map' && (
                <MapChrome
                    cellId={cell.h3}
                    openFlags={poorAccuracy ? 1 : 0}
                    sync={
                        <SyncIndicator
                            connectivity={
                                queue.syncing ? 'syncing' : queue.online ? 'online' : 'offline'
                            }
                            queued={queue.queued + trace.pendingCount}
                            lastSync={
                                queue.lastSyncAt === null
                                    ? null
                                    : new Date(queue.lastSyncAt).toLocaleTimeString([], {
                                          hour: '2-digit',
                                          minute: '2-digit',
                                      })
                            }
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
                                {trace.current === null
                                    ? 'Waiting for a position'
                                    : selectedFootprint === null
                                      ? 'Capture this building'
                                      : 'Capture the marked building'}
                            </Button>
                            <div className="flex gap-2.5">
                                <Button
                                    variant="secondary"
                                    size="field"
                                    fullWidth
                                    disabled={selectedFootprint === null}
                                    onClick={() => {
                                        setSelectedFootprint(null);
                                    }}
                                >
                                    Clear selection
                                </Button>
                                <Button variant="secondary" size="field" fullWidth>
                                    Not a building
                                </Button>
                            </div>
                        </div>
                    }
                >
                    {pack.state === 'installed' && pack.pack !== null ? (
                        <>
                            <FieldMap
                                pack={pack.pack}
                                assignedH3={cell.h3}
                                centre={cell.centre}
                                position={trace.current}
                                track={trace.track}
                                captured={localPoints}
                                visitedFootprintIds={visitedFootprints}
                                selectedFootprintId={selectedFootprint}
                                onSelectFootprint={setSelectedFootprint}
                                onStreetChange={setStreet}
                            />

                            {/* Where the officer is, in words. The map answers
                                where, this answers where by name, which is what
                                gets written on a paper form and said out loud. */}
                            <div className="pointer-events-none absolute inset-x-0 top-0 flex items-start justify-between gap-2 p-2.5">
                                <span className="rounded-sm bg-surface/85 px-2 py-1 text-ui text-ink backdrop-blur-sm">
                                    {street ?? 'Off any mapped street'}
                                </span>
                                {accuracy !== null && (
                                    <span
                                        className={cx(
                                            'rounded-sm bg-surface/85 px-2 py-1 numeric-mono text-mono backdrop-blur-sm',
                                            poorAccuracy ? 'text-amber-ink' : 'text-faint',
                                        )}
                                    >
                                        +/- {accuracy.toFixed(0)} m
                                    </span>
                                )}
                            </div>

                            <div className="pointer-events-none absolute inset-x-0 bottom-0 flex flex-col gap-1.5 p-2.5">
                                {poorAccuracy && (
                                    <p className="max-w-[36ch] rounded-sm bg-surface/85 px-2 py-1 text-ui text-amber-ink backdrop-blur-sm">
                                        Accuracy is poor here. Move into the open before capturing.
                                    </p>
                                )}
                                <FootprintLegend className="rounded-sm bg-surface/85 px-2 py-1 backdrop-blur-sm" />
                            </div>
                        </>
                    ) : (
                        <div className="flex h-full flex-col items-center justify-center gap-4 overflow-y-auto p-4">
                            <PackDownload
                                state={pack.state}
                                offered={pack.offered}
                                received={pack.received}
                                bytes={pack.bytes}
                                error={pack.error}
                                onDownload={pack.download}
                                onCancel={pack.cancel}
                                blocking
                            />

                            {/* Capture still works with no map at all. It is
                                harder, so it is said plainly rather than left to
                                be discovered. */}
                            <p className="max-w-[36ch] text-center text-ui text-muted">
                                You can still capture without the map. You will be working from
                                what you can see rather than from the building outlines.
                            </p>

                            {tracePoints.length > 1 ? (
                                <PresenceMark points={tracePoints} size={120} />
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
                                </div>
                            )}

                            {accuracy !== null && (
                                <p
                                    className={cx(
                                        'numeric-mono text-mono',
                                        poorAccuracy ? 'text-amber-ink' : 'text-faint',
                                    )}
                                >
                                    {trace.current?.latitude.toFixed(5) ?? ''},{' '}
                                    {trace.current?.longitude.toFixed(5) ?? ''}
                                    {'  '}
                                    +/- {accuracy.toFixed(1)} m
                                </p>
                            )}

                            {trace.wakeLock === 'denied' && (
                                <p className="max-w-[36ch] text-center text-ui text-amber-ink">
                                    The screen may switch itself off. If it does, your trace stops
                                    recording, so keep the app open.
                                </p>
                            )}

                            {trace.error !== null && (
                                <p className="max-w-[36ch] text-center text-ui text-alert">
                                    {trace.error}
                                </p>
                            )}
                        </div>
                    )}
                </MapChrome>
            )}

            {stage === 'structure' && (
                <StructureSheet
                    assignmentId={assignmentId}
                    structureTypes={structureTypes}
                    occupancyStatuses={occupancyStatuses}
                    existing={openStructure}
                    position={trace.current}
                    externalFootprintId={selectedFootprint}
                    consentScript={consentScript}
                    cellId={cell.id}
                    cellH3={cell.h3}
                    sessionId={sessionId}
                    record={queue.record}
                    onClose={() => {
                        setStage('map');
                    }}
                    onSaved={(structure) => {
                        flushFixes();
                        setOpenStructure(structure);
                        setRecorded((r) => [
                            structure,
                            ...r.filter((s) => s.clientUuid !== structure.clientUuid),
                        ]);
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
                    sessionId={sessionId}
                    record={queue.record}
                    onClose={() => {
                        setStage('structure');
                    }}
                    onSaved={(enterprise) => {
                        const updated = {
                            ...openStructure,
                            enterprises: [...openStructure.enterprises, enterprise],
                        };
                        setOpenStructure(updated);
                        setRecorded((r) =>
                            r.map((s) => (s.clientUuid === updated.clientUuid ? updated : s)),
                        );
                        setStage('structure');
                    }}
                />
            )}
        </div>
    );
}

interface StructureSheetProps {
    assignmentId: number;
    cellId: number;
    /** For the GPS card: which cell the officer is standing in. */
    cellH3: string;
    sessionId: number | null;
    record: (entity: 'structure' | 'enterprise', payload: Record<string, unknown>) => Promise<string>;
    structureTypes: Option[];
    occupancyStatuses: Option[];
    existing: CapturedStructure | null;
    position: { latitude: number; longitude: number; accuracy_m: number | null } | null;
    /** The footprint tapped on the map, or null when there was nothing to tap. */
    externalFootprintId: number | null;
    consentScript: { version: string; text: string };
    onClose: () => void;
    onSaved: (structure: CapturedStructure) => void;
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
    assignmentId,
    cellId,
    cellH3,
    sessionId,
    record,
    structureTypes,
    occupancyStatuses,
    existing,
    position,
    externalFootprintId,
    consentScript,
    onClose,
    onSaved,
    onCaptureEnterprise,
}: StructureSheetProps) {
    const [type, setType] = useState(existing?.structureType ?? 'shophouse');
    const [occupancy, setOccupancy] = useState(existing?.occupancyStatus ?? 'occupied');
    const [unitCount, setUnitCount] = useState(existing?.unitCount?.toString() ?? '1');
    const [floors, setFloors] = useState(existing?.floors?.toString() ?? '1');
    const [consentGiven, setConsentGiven] = useState(false);
    const [showScript, setShowScript] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [resolvedWard, setResolvedWard] = useState<string | null>(existing?.resolvedWard ?? null);
    const clientUuid = useRef(existing?.clientUuid ?? uuid7());

    /**
     * Whether storeys are a sensible thing to ask about at all.
     *
     * Decided by the type the officer picked, from the same enum the server
     * reads, so a kiosk is never asked and a shophouse always is. Defaults to
     * asking: a type this build does not recognise is more likely a building
     * than not.
     */
    const expectsFloors = structureTypes.find((o) => o.value === type)?.expectsFloors ?? true;

    const save = async () => {
        if (position === null) {
            setError('No position yet. Wait for a fix before saving.');

            return;
        }

        setSaving(true);
        setError(null);

        const units = Number.parseInt(unitCount, 10) || 1;

        // Null for the things that are not buildings. An umbrella stand does not
        // have one storey, and recording it as though it did would put a floor
        // count in the register that nobody counted.
        const storeys = expectsFloors ? Number.parseInt(floors, 10) || 1 : null;

        try {
            // Written to the device and queued. The officer is finished here
            // whether or not there is any signal, which is the whole point.
            await db.structures.put({
                clientUuid: clientUuid.current,
                gridCellId: cellId,
                assignmentId,
                longitude: position.longitude,
                latitude: position.latitude,
                accuracyM: position.accuracy_m,
                structureType: type,
                occupancyStatus: occupancy,
                unitCount: units,
                floors: storeys,
                notes: null,
                observedAt: new Date().toISOString(),
                serverId: null,
                resolvedWard: null,
                externalFootprintId,
            });

            await record('structure', {
                client_uuid: clientUuid.current,
                // A new observation each time this is saved, which is what keeps
                // a revisit from overwriting the last one.
                observation_uuid: uuid7(),
                grid_cell_id: cellId,
                longitude: position.longitude,
                latitude: position.latitude,
                accuracy_m: position.accuracy_m,
                structure_type: type,
                occupancy_status: occupancy,
                unit_count: units,
                ...(storeys === null ? {} : { floors: storeys }),
                observed_at: new Date().toISOString(),
                assignment_id: assignmentId,
                // The building the officer pointed at. The server checks it is
                // real and inside the cell; nothing here is taken on trust.
                ...(externalFootprintId === null
                    ? {}
                    : { external_footprint_id: externalFootprintId }),
                ...(sessionId === null ? {} : { field_session_id: sessionId }),
            });

            // Whatever the server later resolves is shown when it arrives. Until
            // then the officer is told plainly that the work is on the device.
            const stored = await db.structures.get(clientUuid.current);
            setResolvedWard(stored?.resolvedWard ?? null);

            onSaved({
                id: stored?.serverId ?? 0,
                clientUuid: clientUuid.current,
                structureType: type,
                unitCount: units,
                floors: storeys,
                occupancyStatus: occupancy,
                resolvedWard: stored?.resolvedWard ?? null,
                enterprises: existing?.enterprises ?? [],
                priorObservation: existing?.priorObservation ?? null,
            });
        } catch (e) {
            setError(e instanceof Error ? e.message : 'That did not save to this device.');
        } finally {
            setSaving(false);
        }
    };

    const units = Number.parseInt(unitCount, 10);
    const enterprises = existing?.enterprises ?? [];
    const expectsEnterprises =
        occupancyStatuses.find((o) => o.value === occupancy)?.expectsEnterprises ?? false;

    return (
        <div className="flex h-full flex-col">
            <CaptureStepHeader
                step={existing === null ? 2 : 3}
                title={existing === null ? 'New capture' : 'Photos and businesses'}
                subtitle={existing === null ? 'The building: what it is and who agreed' : 'Take the required photos, then add each business'}
                onClose={onClose}
            />

            <div className="min-h-0 flex-1">
                <Sheet
                    title={existing === null ? 'Building' : 'Building saved'}
                    footer={
                        <div className="flex flex-col gap-2">
                            {error !== null && <p className="text-ui text-alert">{error}</p>}
                            <Button
                                variant="primary"
                                size="field-primary"
                                fullWidth
                                busy={saving}
                                disabled={(!consentGiven && expectsEnterprises) || position === null}
                                onClick={() => {
                                    void save();
                                }}
                            >
                                {existing === null ? 'Next: photos and businesses' : 'Save changes'}
                            </Button>
                        </div>
                    }
                    footerNote={
                        resolvedWard === null
                            ? 'Saved on this device. It syncs on its own when there is signal.'
                            : `Recorded in ${resolvedWard} ward.`
                    }
                >
                    <div className="flex flex-col gap-3 px-4 pt-4">
                        <GpsCard position={position} cellH3={cellH3} />
                        <SupervisorNote />
                    </div>

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

                            {expectsFloors && (
                                <TextField
                                    label="Storeys"
                                    size="field"
                                    type="number"
                                    min={1}
                                    max={200}
                                    value={floors}
                                    onChange={(e) => {
                                        setFloors(e.target.value);
                                    }}
                                    hint="Count the ground floor as one. A shop with a flat above is two."
                                />
                            )}

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
                                                className="flex items-center justify-between gap-3 rounded-card border border-rule px-3 py-2 bg-raised"
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
                                    <p className="rounded-card border border-rule bg-raised p-3 text-body text-ink">
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

                    {existing !== null && (
                        <SheetSection label="Photographs">
                            <PhotoCapture
                                structureClientUuid={existing.clientUuid}
                                position={position}
                            />
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
    sessionId,
    record,
    onClose,
    onSaved,
}: {
    structure: CapturedStructure;
    sessionId: number | null;
    record: (entity: 'structure' | 'enterprise', payload: Record<string, unknown>) => Promise<string>;
    onClose: () => void;
    onSaved: (enterprise: CapturedEnterprise) => void;
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
    const [floor, setFloor] = useState('0');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const unitLabel = `Unit ${String(structure.enterprises.length + 1)}`;

    /*
     * Asked only where there is more than one answer. In a single storey
     * shophouse every business is on the ground floor, and a select with one
     * option in it is a tap that teaches the officer the form wastes their time.
     */
    const storeys = structure.floors ?? 1;
    const asksFloor = storeys > 1;
    const recordedFloor = asksFloor ? Number.parseInt(floor, 10) : 0;

    const save = async () => {
        setSaving(true);
        setError(null);

        const clientUuid = uuid7();

        try {
            await db.enterprises.put({
                clientUuid,
                structureClientUuid: structure.clientUuid,
                unitLabel,
                floor: recordedFloor,
                tradingName: tradingName.trim(),
                sectorCode: sector?.code ?? null,
                scaleBand: scale,
                signageObserved: signage,
                observedAt: new Date().toISOString(),
                serverId: null,
            });

            await record('enterprise', {
                client_uuid: clientUuid,
                observation_uuid: uuid7(),
                // Referenced by the building's own client uuid, never a server id
                // the handset may not have been told yet. That is what lets a
                // business be captured before its building has synced.
                structure_client_uuid: structure.clientUuid,
                unit_label: unitLabel,
                floor: recordedFloor,
                trading_name: tradingName.trim(),
                sector_code: sector?.code ?? null,
                scale_band: scale,
                signage_observed: signage,
                observed_at: new Date().toISOString(),
                ...(sessionId === null ? {} : { field_session_id: sessionId }),
            });

            onSaved({
                id: 0,
                unitLabel,
                floor: recordedFloor,
                tradingName: tradingName.trim(),
                sectorCode: sector?.code ?? null,
            });
        } catch (e) {
            setError(e instanceof Error ? e.message : 'That did not save to this device.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="flex h-full flex-col">
            <CaptureStepHeader
                step={4}
                title="Business details"
                subtitle={`${unitLabel} of ${String(structure.unitCount ?? 1)} in this building`}
                onClose={onClose}
            />

            <div className="min-h-0 flex-1">
                <Sheet
                    title="Business"
                    meta={`${unitLabel} of ${String(structure.unitCount ?? 1)}`}
                    footer={
                        <div className="flex flex-col gap-2">
                            {error !== null && <p className="text-ui text-alert">{error}</p>}
                            <Button
                                variant="primary"
                                size="field-primary"
                                fullWidth
                                busy={saving}
                                disabled={tradingName.trim() === '' || sector === null}
                                onClick={() => {
                                    void save();
                                }}
                            >
                                Save business
                            </Button>
                        </div>
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

                            {asksFloor && (
                                <SelectField
                                    label="Which floor"
                                    size="field"
                                    value={floor}
                                    onChange={(e) => {
                                        setFloor(e.target.value);
                                    }}
                                >
                                    {floorOptions(structure.floors).map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                                </SelectField>
                            )}

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
