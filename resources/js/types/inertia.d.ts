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

declare module '@inertiajs/core' {
    /**
     * What every page receives, shared from the server rather than passed per
     * page. Keep this in step with HandleInertiaRequests::share.
     */
    interface PageProps {
        auth: { user: AuthUser | null };
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
        } | null;
    }
}
