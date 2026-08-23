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
    }
}
