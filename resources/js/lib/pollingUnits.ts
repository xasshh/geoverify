/** What SummarisePollingUnits sends, to the console and the client alike. */
export interface PollingUnitSummary {
    total: number;
    byStatus: Record<'listed' | 'assigned' | 'filed' | 'confirmed' | 'not_found', number>;
    settled: number;
    confirmed: number;
    notFound: number;
    moved: number;
    withoutListedPoint: number;
    concerns: { key: string; label: string; count: number }[];
    venues: { key: string; label: string; count: number }[];
    byLga: { lga: string; total: number; settled: number; confirmed: number; notFound: number; moved: number; withConcerns: number }[];
}

export const STATUS_LABEL = {
    listed: 'Not yet sent',
    assigned: 'With an officer',
    filed: 'Filed, to read',
    confirmed: 'Confirmed',
    not_found: 'Not found',
} as const;

/** The map's dot for each state a client may see. */
export const UNIT_COLOUR = {
    confirmed: '#2E8B57',
    moved: '#D9922E',
    not_found: '#C2453D',
    pending: '#9AA6A1',
} as const;
