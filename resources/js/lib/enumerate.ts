import type { StatusTone } from '@/lib/status';

/** What every signed-in Enumerate page is handed for its frame. */
export interface EnumerateFrame {
    name: string;
    walletMinor: number;
    active: number;
    /** The organisation being acted for, or null when acting as oneself. */
    organisation: {
        id: number;
        name: string;
        status: 'pending' | 'approved' | 'suspended';
        role: string;
        roleLabel: string;
        seats: number;
        accountManager: string | null;
        can: { request: boolean; bulk: boolean; project: boolean; fund: boolean; team: boolean };
    } | null;
    organisations: { id: number; name: string; role: string; status: string }[];
    invitations: { id: number; organisation: string; role: string }[];
}

export type RequestStatus =
    | 'paid'
    | 'registry_check'
    | 'passed'
    | 'failed'
    | 'awaiting_agent'
    | 'agent_assigned'
    | 'on_site'
    | 'monitoring'
    | 'completed';

/** A request as the tables list it. */
export interface RequestRow {
    reference: string;
    business: string;
    tier: 1 | 2 | 3;
    tierLabel: string;
    monitoringDays: number | null;
    status: RequestStatus;
    statusLabel: string;
    statusNote: string | null;
    requestedAt: string;
    amountMinor: number;
}

export interface Prices {
    tier1: number;
    tier2: number;
    tier3: Record<string, number>;
}

export interface RegistryMatch {
    name: string;
    rcNumber: string;
    companyType: string;
    status: string | null;
    place: string | null;
}

export const STATUS_TONE: Record<RequestStatus, StatusTone> = {
    paid: 'progress',
    registry_check: 'progress',
    passed: 'accepted',
    failed: 'rejected',
    awaiting_agent: 'held',
    agent_assigned: 'progress',
    on_site: 'progress',
    monitoring: 'progress',
    completed: 'accepted',
};

/** ₦56,178.00 in the wallet, ₦12,000 in a table: the same kobo, two dressings. */
export function kobo(minor: number, decimals: 0 | 2 = 0): string {
    return `₦${(minor / 100).toLocaleString('en-NG', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}`;
}

/** "17 Sep 2026". */
export function day(iso: string | null): string {
    if (iso === null) {
        return '';
    }

    return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

/** "17 Sep · 09:12", as the progress bar stamps each step. */
export function moment(iso: string | null): string {
    if (iso === null) {
        return '';
    }

    const d = new Date(iso);

    return `${d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })} · ${d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}`;
}

/** CAC's company types, as a person would say them. */
export function companyType(type: string): string {
    return (
        {
            COMPANY: 'Private company',
            BUSINESS_NAME: 'Business name',
            INCORPORATED_TRUSTEES: 'Incorporated trustees',
            LIMITED_PARTNERSHIP: 'Limited partnership',
            LIMITED_LIABILITY_PARTNERSHIP: 'Limited liability partnership',
        }[type] ?? type
    );
}

/** RC 1482093 or BN 3309127, by the kind of registration. */
export function registration(rcNumber: string, type: string): string {
    return `${type === 'BUSINESS_NAME' ? 'BN' : type === 'INCORPORATED_TRUSTEES' ? 'IT' : 'RC'} ${rcNumber}`;
}

/** One day of a Tier 3 monitoring period, as the calendar draws it. */
export interface CalendarDay {
    day: number;
    date: string;
    weekday: string;
    state: 'open' | 'low' | 'closed' | 'pending' | 'no_visit' | 'upcoming' | 'missed';
    today: boolean;
}

/** Each day's square, in the calendar's colours: trading, quiet, closed, and what has not happened. */
export const DAY_TONE: Record<CalendarDay['state'], string> = {
    open: 'bg-gold text-on-accent border-gold',
    low: 'bg-amber-soft text-amber-ink border-amber',
    closed: 'bg-graphite-soft text-ink border-rule-strong',
    pending: 'bg-held-soft text-held-ink border-held',
    no_visit: 'bg-sunken text-muted border-rule',
    missed: 'bg-raised text-alert-ink border-alert border-dashed',
    upcoming: 'bg-raised text-faint border-rule',
};
