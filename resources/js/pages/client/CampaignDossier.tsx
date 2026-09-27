import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import {
    Map as MapLibreMap,
    NavigationControl,
    type GeoJSONSource,
} from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import '@/lib/maplibre';
import { CampaignIntroModal, Progress } from '@/components/CampaignWidgets';
import { ClientShell } from '@/components/ClientShell';
import { StatusPill } from '@/components/StatusPill';
import { campaignTone, on, type CampaignDossier as Dossier } from '@/lib/campaign';
import { readPalette } from '@/lib/mapStyle';

interface RoadLabel {
    name: string;
    highway: string;
    metres: number;
    lon: number;
    lat: number;
}

interface RoadsResponse {
    roads: GeoJSON.FeatureCollection;
    labels: RoadLabel[];
}

interface Props {
    campaign: Dossier;
    mustAcknowledge: boolean;
    briefUrl: string;
    roadsUrl: string;
}

function Section({
    id,
    title,
    caption,
    children,
}: {
    id?: string;
    title: string;
    caption?: string;
    children: React.ReactNode;
}) {
    return (
        <section id={id} className="mt-10 scroll-mt-6">
            <header className="border-b border-rule pb-2">
                <h2 className="font-display text-display-s text-ink">{title}</h2>
                {caption !== undefined && <p className="text-label text-faint">{caption}</p>}
            </header>
            <div className="mt-4">{children}</div>
        </section>
    );
}

/**
 * The extent of a set of geometries.
 *
 * Walks the coordinate arrays rather than reading them out of serialised JSON.
 * A polygon and a multipolygon nest to different depths, so this recurses to
 * whatever depth it finds a pair of numbers at instead of assuming one.
 */
function boundsOf(
    features: GeoJSON.Feature[],
): [[number, number], [number, number]] | null {
    let minLon = Infinity;
    let minLat = Infinity;
    let maxLon = -Infinity;
    let maxLat = -Infinity;

    const visit = (node: unknown): void => {
        if (!Array.isArray(node)) {
            return;
        }

        if (typeof node[0] === 'number' && typeof node[1] === 'number') {
            minLon = Math.min(minLon, node[0]);
            maxLon = Math.max(maxLon, node[0]);
            minLat = Math.min(minLat, node[1]);
            maxLat = Math.max(maxLat, node[1]);

            return;
        }

        node.forEach(visit);
    };

    features.forEach((feature) => {
        if ('coordinates' in feature.geometry) {
            visit(feature.geometry.coordinates);
        }
    });

    return Number.isFinite(minLon)
        ? [
              [minLon, minLat],
              [maxLon, maxLat],
          ]
        : null;
}

/**
 * The coverage, drawn.
 *
 * No basemap, and none is needed: the street network and the mandate outline
 * both come out of this system's own PostGIS, so the map is built from the same
 * data the register is. Billed tile providers stay out of the question.
 *
 * Roads arrive on their own request after the outline has painted. The outline
 * and the numbers are what the page is about; the roads are a quarter of a
 * megabyte of context that can land a moment later without anybody noticing.
 */
function CoverageMap({
    areas,
    roadsUrl,
}: {
    areas: Dossier['coverage']['areas'];
    roadsUrl: string;
}) {
    const container = useRef<HTMLDivElement | null>(null);
    const map = useRef<MapLibreMap | null>(null);
    const [labels, setLabels] = useState<RoadLabel[]>([]);
    const [placed, setPlaced] = useState<Array<RoadLabel & { x: number; y: number }>>([]);

    /*
     * Memoised, and it has to be.
     *
     * This array is the effect's dependency, and the effect's cleanup removes
     * the map. Rebuilding it on every render means every state change in this
     * component, the labels landing included, tears the map down and builds a
     * new one, which fetches again and sets the labels again. The first version
     * of this looped until there was no canvas left.
     */
    const features = useMemo<GeoJSON.Feature[]>(
        () =>
            areas
                .filter((area) => area.outline !== null)
                .map((area) => ({
                    type: 'Feature',
                    geometry: area.outline as GeoJSON.Geometry,
                    properties: { name: area.name },
                })),
        [areas],
    );

    useEffect(() => {
        if (container.current === null || map.current !== null || features.length === 0) {
            return;
        }

        const palette = readPalette(container.current);

        const instance = new MapLibreMap({
            container: container.current,
            style: {
                version: 8,
                sources: {},
                layers: [
                    {
                        id: 'ground',
                        type: 'background',
                        paint: { 'background-color': palette.sunken },
                    },
                ],
            },
            maxPitch: 0,
            attributionControl: false,
        });

        map.current = instance;
        instance.addControl(new NavigationControl({ showCompass: false }), 'top-right');

        instance.on('load', () => {
            instance.addSource('areas', {
                type: 'geojson',
                data: { type: 'FeatureCollection', features },
            });

            // The mandate sits under the roads: it is the ground, and a boundary
            // drawn over the street network hides the junctions that make the
            // shape readable.
            instance.addLayer({
                id: 'areas-fill',
                type: 'fill',
                source: 'areas',
                paint: { 'fill-color': palette.surface, 'fill-opacity': 1 },
            });

            instance.addSource('roads', {
                type: 'geojson',
                data: { type: 'FeatureCollection', features: [] },
            });

            /*
             * Casing under colour, which is what makes a junction read as a
             * junction rather than as a smudge: the halo separates two roads
             * crossing from one road forking.
             */
            instance.addLayer({
                id: 'roads-casing',
                type: 'line',
                source: 'roads',
                layout: { 'line-cap': 'round', 'line-join': 'round' },
                paint: {
                    'line-color': palette.surface,
                    'line-width': [
                        'interpolate',
                        ['linear'],
                        ['zoom'],
                        8,
                        ['match', ['get', 'highway'], ['motorway', 'trunk'], 5, 3],
                        14,
                        ['match', ['get', 'highway'], ['motorway', 'trunk'], 13, 7],
                    ],
                },
            });

            instance.addLayer({
                id: 'roads',
                type: 'line',
                source: 'roads',
                layout: { 'line-cap': 'round', 'line-join': 'round' },
                paint: {
                    // Class is hierarchy, not decoration. A trunk route has to
                    // be findable at a glance or the map has no skeleton.
                    'line-color': [
                        'match',
                        ['get', 'highway'],
                        ['motorway', 'trunk'],
                        palette.gold,
                        ['primary'],
                        palette.graphite,
                        palette.ruleStrong,
                    ],
                    'line-width': [
                        'interpolate',
                        ['linear'],
                        ['zoom'],
                        8,
                        ['match', ['get', 'highway'], ['motorway', 'trunk'], 2.4, 1.1],
                        14,
                        ['match', ['get', 'highway'], ['motorway', 'trunk'], 7, 3],
                    ],
                },
            });

            instance.addLayer({
                id: 'areas-line',
                type: 'line',
                source: 'areas',
                paint: { 'line-color': palette.gold, 'line-width': 1.6 },
            });

            const bounds = boundsOf(features);

            if (bounds !== null) {
                instance.fitBounds(bounds, { padding: 32, animate: false });
            }

            void fetch(roadsUrl)
                .then((response) => response.json() as Promise<RoadsResponse>)
                .then((payload) => {
                    const source = instance.getSource<GeoJSONSource>('roads');

                    if (source !== undefined) {
                        void source.setData(payload.roads);
                    }

                    setLabels(payload.labels);
                })
                .catch(() => {
                    // A map without street names is still a map. Nothing here
                    // is worth an error state on a dossier page.
                });
        });

        const observer = new ResizeObserver(() => {
            instance.resize();

            const bounds = boundsOf(features);

            if (bounds !== null) {
                instance.fitBounds(bounds, { padding: 32, animate: false });
            }
        });

        observer.observe(container.current);

        return () => {
            observer.disconnect();
            instance.remove();
            map.current = null;
        };
    }, [features, roadsUrl]);

    /*
     * Labels are HTML, not map symbols.
     *
     * A symbol layer needs a glyph endpoint, and this application self hosts its
     * typefaces as web fonts rather than as rendered glyph ranges. Building that
     * pipeline to put thirty street names on one map would be a second
     * typographic system to keep in step with the first. Projecting a handful of
     * points and letting the browser set them in the interface face costs nothing and
     * matches every other number on the page.
     */
    useEffect(() => {
        const instance = map.current;

        if (instance === null || labels.length === 0) {
            return;
        }

        const reproject = (): void => {
            const canvas = instance.getCanvas();

            /*
             * Greedy placement, longest road first.
             *
             * The labels arrive ordered by how much of each road is inside the
             * boundary, so taking them in order and dropping any that would
             * collide keeps the roads that define the place and loses the ones
             * that only crowd it. Abuja's core has a dozen named roads inside a
             * centimetre of screen, and without this they stack into an
             * unreadable block over exactly the part of the map somebody is
             * looking at.
             */
            const taken: Array<{ x: number; y: number; w: number; h: number }> = [];
            const height = 15;

            const kept = labels
                .map((label) => {
                    const point = instance.project([label.lon, label.lat]);

                    return { ...label, x: point.x, y: point.y };
                })
                // Off canvas labels are dropped rather than clipped, so the
                // container never has to scroll and nothing is half drawn at
                // the edge.
                .filter(
                    (label) =>
                        label.x > 50 &&
                        label.y > 14 &&
                        label.x < canvas.clientWidth - 50 &&
                        label.y < canvas.clientHeight - 14,
                )
                .filter((label) => {
                    // Roughly six pixels a character at this size. An exact
                    // measurement would need a layout pass per label per frame,
                    // and being a few pixels generous only drops a label that
                    // was about to be tight anyway.
                    const width = label.name.length * 6 + 10;
                    const box = { x: label.x, y: label.y, w: width, h: height };

                    const clashes = taken.some(
                        (other) =>
                            Math.abs(other.x - box.x) < (other.w + box.w) / 2 &&
                            Math.abs(other.y - box.y) < (other.h + box.h) / 2 + 2,
                    );

                    if (clashes) {
                        return false;
                    }

                    taken.push(box);

                    return true;
                });

            setPlaced(kept);
        };

        reproject();
        instance.on('move', reproject);
        instance.on('resize', reproject);

        return () => {
            instance.off('move', reproject);
            instance.off('resize', reproject);
        };
    }, [labels]);

    if (features.length === 0) {
        return (
            <p className="rounded-sm border border-dashed border-rule px-4 py-6 text-center text-ui text-muted">
                No boundary has been drawn for this scope yet.
            </p>
        );
    }

    return (
        <div className="relative">
            <div ref={container} className="h-[420px] w-full rounded-card border border-rule bg-raised" />

            {/* ODbL requires it wherever the network is shown, the same way the
                boundary licences do on a published export. */}
            <p className="pointer-events-none absolute right-2 bottom-1 numeric-mono text-label text-faint">
                Streets &copy; OpenStreetMap contributors
            </p>

            <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-sm">
                {placed.map((label) => (
                    <span
                        key={label.name}
                        className="absolute -translate-x-1/2 -translate-y-1/2 rounded-[2px] bg-surface/85 px-1 text-label whitespace-nowrap text-muted"
                        style={{ left: label.x, top: label.y }}
                    >
                        {label.name}
                    </span>
                ))}
            </div>
        </div>
    );
}

/**
 * The campaign dossier.
 *
 * Everything about one exercise on one page, reachable from the dashboard, from
 * the list, and directly by URL. The order is the order somebody asks in: what
 * is it, how long, where, what are you recording, who have you spoken to, and
 * who is doing it.
 */
export default function CampaignDossier({ campaign, mustAcknowledge, briefUrl, roadsUrl }: Props) {
    const [dismissed, setDismissed] = useState(false);
    const { timeline, collection, coverage, deployment, schema, stakeholders } = campaign;

    return (
        <ClientShell current="campaigns" organisation={{ name: campaign.client.name }}>
            <Head title={campaign.name} />

            <header className="mt-8 flex flex-wrap items-start justify-between gap-4 border-b border-rule pb-3">
                <div>
                    <p className="text-label font-semibold tracking-[0.05em] text-gold uppercase">
                        <Link href="/client/campaigns" className="underline underline-offset-2">
                            All campaigns
                        </Link>
                        <span className="numeric-mono ml-2 text-faint">{campaign.code}</span>
                    </p>
                    <h1 className="font-display text-display-l text-ink">{campaign.name}</h1>
                    <p className="mt-1 text-body text-muted">{campaign.subjectType}</p>
                </div>

                <div className="flex flex-col items-end gap-2">
                    <StatusPill
                        tone={campaignTone(campaign.status)}
                        label={campaign.statusLabel}
                        emphasis="filled"
                    />
                    <a
                        href={briefUrl}
                        className="text-ui text-gold underline underline-offset-2"
                    >
                        Download the one page brief
                    </a>
                    {!mustAcknowledge && (
                        <button
                            type="button"
                            onClick={() => {
                                setDismissed(false);
                            }}
                            className="text-label text-muted underline underline-offset-2"
                        >
                            View brief again
                        </button>
                    )}
                </div>
            </header>

            {campaign.objective !== null && (
                <p className="mt-6 max-w-[70ch] border-l-2 border-gold pl-4 text-body text-ink">
                    {campaign.objective}
                </p>
            )}

            <div className="mt-6 grid gap-6 md:grid-cols-2">
                <Progress
                    label="Timeline"
                    value={
                        timeline.daysRemaining === null
                            ? 'no end date'
                            : `${String(Math.max(0, timeline.daysRemaining))} days left`
                    }
                    percent={timeline.elapsedPercent}
                    caption={`${on(timeline.startsOn)} to ${on(timeline.endsOn)}`}
                />
                <Progress
                    label="Records gathered"
                    value={collection.gathered.toLocaleString()}
                    total={collection.target?.toLocaleString() ?? null}
                    percent={collection.percent}
                    tone="green"
                    caption={`${collection.accepted.toLocaleString()} accepted into the register`}
                />
            </div>

            {campaign.about !== null && (
                <Section title="About this exercise">
                    {/* Paragraphs, not a wrapped block. The brief is stored
                        hard wrapped for the PDF, so the newlines inside a
                        paragraph are an artefact of storage rather than
                        meaning, and rendering them would set the prose ragged
                        at whatever width it was typed at. */}
                    <div className="flex max-w-[70ch] flex-col gap-3">
                        {campaign.about
                            .split(/\n\s*\n/)
                            .map((paragraph) => paragraph.replace(/\s+/g, ' ').trim())
                            .filter((paragraph) => paragraph !== '')
                            .map((paragraph) => (
                                <p key={paragraph.slice(0, 40)} className="text-body text-ink">
                                    {paragraph}
                                </p>
                            ))}
                    </div>
                </Section>
            )}

            <Section
                title="Coverage"
                caption={`${String(coverage.areaCount)} ${coverage.areaCount === 1 ? 'area' : 'areas'}${coverage.states.length > 0 ? ` · ${coverage.states.join(', ')}` : ''}`}
            >
                <CoverageMap areas={coverage.areas} roadsUrl={roadsUrl} />

                <ul className="mt-4 flex flex-col rounded-card border border-rule px-4 bg-raised">
                    {coverage.areas.map((area) => (
                        <li
                            key={area.id}
                            className="flex flex-wrap items-baseline gap-x-6 gap-y-1 border-b border-rule py-2.5 last:border-b-0"
                        >
                            <span className="min-w-[180px] flex-1 text-ui text-ink">
                                {area.name}
                            </span>
                            <span className="text-label text-muted">
                                {area.state ?? '.'} &middot; {area.lga ?? '.'}
                            </span>
                            <span className="numeric-mono text-label text-faint">
                                {area.areaKm2} km2
                            </span>
                            <span className="numeric-mono text-label text-faint">
                                {area.cells.toLocaleString()} cells
                            </span>
                        </li>
                    ))}
                </ul>
            </Section>

            <Section
                title="What is being collected"
                caption={`${String(schema.fieldCount)} fields, ${String(schema.requiredCount)} required`}
            >
                <table className="w-full text-left">
                    <thead>
                        <tr className="border-b border-rule">
                            {['Field', 'Type', 'Required', 'Notes'].map((heading) => (
                                <th
                                    key={heading}
                                    className="pb-2 text-label font-semibold tracking-[0.05em] text-faint uppercase"
                                >
                                    {heading}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {schema.fields.map((field) => (
                            <tr key={field.id} className="border-b border-rule last:border-b-0">
                                <td className="py-2 text-ui text-ink">{field.label}</td>
                                <td className="py-2 text-label text-muted">{field.typeLabel}</td>
                                <td className="py-2 numeric-mono text-label text-faint">
                                    {field.isRequired ? 'yes' : 'no'}
                                </td>
                                <td className="py-2 text-label text-faint">
                                    {field.options !== null
                                        ? field.options.join(', ')
                                        : (field.helpText ?? '')}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Section>

            <Section title="Stakeholders" caption={`${String(stakeholders.total)} recorded`}>
                <div className="flex flex-col gap-5">
                    {stakeholders.byCategory.map((group) => (
                        <div key={group.category}>
                            <p className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                                {group.label} &middot; {group.count}
                            </p>
                            <ul className="mt-1.5 flex flex-col rounded-card border border-rule px-4 bg-raised">
                                {group.people.map((person) => (
                                    <li
                                        key={person.id}
                                        className="flex flex-wrap items-baseline gap-x-6 gap-y-1 border-b border-rule py-2 last:border-b-0"
                                    >
                                        <span className="min-w-[180px] flex-1 text-ui text-ink">
                                            {person.name}
                                        </span>
                                        <span className="text-label text-muted">
                                            {person.organisation ?? ''}
                                            {person.roleTitle !== null && ` · ${person.roleTitle}`}
                                        </span>
                                        <span className="numeric-mono text-label text-faint">
                                            {person.engagementLabel}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}

                    {stakeholders.total === 0 && (
                        <p className="text-ui text-muted">No stakeholders recorded yet.</p>
                    )}
                </div>
            </Section>

            <Section
                id="roster"
                title="Agent roster"
                caption={`${String(deployment.activeCount)} currently deployed`}
            >
                <ul className="flex flex-col rounded-card border border-rule px-4 bg-raised">
                    {deployment.roster.map((agent) => (
                        <li
                            key={agent.id}
                            className="flex flex-wrap items-baseline gap-x-6 gap-y-1 border-b border-rule py-2.5 last:border-b-0"
                        >
                            <span className="min-w-[160px] flex-1 text-ui text-ink">
                                {agent.name}
                            </span>
                            <span className="numeric-mono text-label text-faint">
                                {agent.staffRef ?? '.'}
                            </span>
                            <span className="text-label text-muted">
                                {agent.area ?? 'not yet assigned to an area'}
                            </span>
                            <span className="numeric-mono text-label text-faint">
                                {agent.openCells} open
                            </span>
                            <span className="numeric-mono text-label text-faint">
                                since {on(agent.assignedAt)}
                            </span>
                        </li>
                    ))}

                    {deployment.roster.length === 0 && (
                        <li className="py-4 text-ui text-muted">Nobody is deployed yet.</li>
                    )}
                </ul>
            </Section>

            {mustAcknowledge && !dismissed && (
                <CampaignIntroModal
                    campaign={campaign}
                    onDismissed={() => {
                        setDismissed(true);
                    }}
                />
            )}
        </ClientShell>
    );
}
