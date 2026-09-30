import '@inertiajs/core';

/** A signed in person, as shared by HandleInertiaRequests. */
export interface AuthUser {
    id: number;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    staffRef: string | null;
}

/** A signed in portal account, and the business its sidebar is about. */
export interface PortalAuth {
    id: number;
    name: string;
    /** The acting membership's role: owner, manager or viewer. */
    role: string | null;
    business: {
        id: number;
        name: string;
        place: string | null;
        openOrders: number;
        strength: { percent: number; missing: string[]; hint: string };
    } | null;
    invitations: number;
}

/** A signed in investor, and whether their organisation has passed KYC. */
export interface InvestorAuth {
    name: string;
    title: string | null;
    organisation: string | null;
    verified: boolean;
}

declare module '@inertiajs/core' {
    /**
     * What every page receives, shared from the server rather than passed per
     * page. Keep this in step with HandleInertiaRequests::share.
     */
    interface PageProps {
        auth: { user: AuthUser | null; portal: PortalAuth | null; investor: InvestorAuth | null };
        flash: { status: string | null };
        /**
         * What is waiting, for the console sidebar. Null off the console and
         * null for anyone who cannot act on it, so the shape says whether the
         * numbers mean anything rather than leaving a zero to be misread.
         */
        console: {
            review: number;
            claims: number;
            corrections: number;
            escalations: number;
            orders: number;
            messages: number;
            inspections: number;
            deskChecks: number;
            enumerateVisits: number;
            support: number;
            organisations: number;
        } | null;
    }
}
