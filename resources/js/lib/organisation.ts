/** Enumerate for Organisations: the shapes its pages share. */

export interface TeamMember {
    id: number;
    name: string;
    role: string;
    roleLabel: string;
    pending: boolean;
}

export interface BatchRow {
    reference: string;
    tier: number;
    monitoringDays: number | null;
    total: number;
    placed: number;
    refused: number;
    totalMinor: number;
    at: string | null;
}
