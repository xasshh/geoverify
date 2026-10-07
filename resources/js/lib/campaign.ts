/**
 * The dossier, as the server assembles it.
 *
 * One shape for the client dashboard, the About page, the intro modal and the
 * super admin's view, because they are all reading the same thing at different
 * depths. Notably absent, and absent on the server too: anything commercial.
 * AssembleCampaignDossier has no code path to the contract value, so there is no
 * field here to forget to strip.
 */

export interface CampaignArea {
    id: number;
    name: string;
    state: string | null;
    lga: string | null;
    lgaCode: string | null;
    areaKm2: number;
    cells: number;
    targetRecordCount: number | null;
    outline: GeoJSON.Geometry | null;
}

export interface CampaignFieldRow {
    id: number;
    label: string;
    key: string;
    type: string;
    typeLabel: string;
    isRequired: boolean;
    options: string[] | null;
    helpText: string | null;
}

export interface StakeholderRow {
    id: number;
    name: string;
    organisation: string | null;
    roleTitle: string | null;
    contactPerson: string | null;
    engagementStatus: string;
    engagementLabel: string;
    notes: string | null;
    visibleToClient: boolean;
}

/** One question a feature class asks, frozen in a version of its form. */
export interface FeatureAttribute {
    key: string;
    label: string;
    type: string;
    options?: string[];
    required: boolean;
    help_text?: string;
    unit?: string;
    /** Answerable only on the ground, so a desk capture may leave it. */
    field_only: boolean;
}

/** A kind of thing an officer draws: forest, a river, a water point. */
export interface FeatureClassRow {
    id: number;
    key: string;
    label: string;
    geometryType: string;
    geometryLabel: string;
    style: { fill?: string; stroke?: string; icon?: string } | null;
    exclusivityGroup: string | null;
    description: string | null;
    isActive: boolean;
    sortOrder: number;
    version: number | null;
    attributes: FeatureAttribute[];
}

export interface CampaignDossier {
    id: number;
    code: string;
    name: string;
    subjectType: string;
    about: string | null;
    objective: string | null;
    status: string;
    statusLabel: string;
    isLive: boolean;
    client: { id: number | null; name: string | null; shortCode: string | null };

    timeline: {
        startsOn: string | null;
        endsOn: string | null;
        daysElapsed: number | null;
        daysRemaining: number | null;
        elapsedPercent: number | null;
        overrun: boolean;
    };
    collection: {
        gathered: number;
        accepted: number;
        target: number | null;
        percent: number | null;
    };
    coverage: {
        areas: CampaignArea[];
        areaCount: number;
        states: string[];
    };
    deployment: {
        activeCount: number;
        roster: Array<{
            id: number;
            name: string;
            staffRef: string | null;
            area: string | null;
            openCells: number;
            assignedAt: string;
        }>;
    };
    schema: {
        fieldCount: number;
        requiredCount: number;
        fields: CampaignFieldRow[];
    };
    capture: {
        modes: Array<{ value: string; label: string }>;
        areaFeatures: boolean;
        settings: {
            minMappingUnitHa: number | null;
            fieldMaxAccuracyM: number | null;
            verificationSamplePct: number;
            boundaryToleranceM: number;
        };
        classes: FeatureClassRow[];
    };
    stakeholders: {
        total: number;
        byCategory: Array<{
            category: string;
            label: string;
            count: number;
            people: StakeholderRow[];
        }>;
    };
}

/** The status tone, mapped onto the design system's existing six. */
export function campaignTone(
    status: string,
): 'accepted' | 'review' | 'rejected' | 'progress' | 'idle' | 'held' {
    switch (status) {
        case 'active':
            return 'progress';
        case 'completed':
            return 'accepted';
        case 'approved':
            return 'held';
        case 'pending_approval':
            return 'review';
        case 'paused':
            return 'rejected';
        default:
            return 'idle';
    }
}

/** A date the way a Nigerian office writes it. */
export function on(iso: string | null): string {
    return iso === null
        ? 'not set'
        : new Date(`${iso}T00:00:00`).toLocaleDateString('en-NG', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          });
}
