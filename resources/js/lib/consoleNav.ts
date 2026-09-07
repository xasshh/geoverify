/**
 * The console's working views.
 *
 * Collected here because the navigation was built by repeating the same array on
 * every page, and adding a view meant editing all of them and hoping none was
 * missed. A link that exists on four screens out of five reads as a bug in the
 * one that lacks it.
 *
 * Each view carries its own mark. Not decoration: the sidebar is read a hundred
 * times a day by the same person, and a shape is found faster than a word once
 * you already know where you are going.
 */
export type ConsoleView =
    | 'coverage'
    | 'review'
    | 'claims'
    | 'corrections'
    | 'orders'
    | 'live'
    | 'exports'
    | 'escalations'
    | 'audit'
    | 'people'
    | 'mandates'
    | 'campaigns';

/** Which waiting count belongs against a view, where one does. */
export type ConsoleQueue = 'review' | 'claims' | 'corrections' | 'escalations' | 'orders';

export interface ConsoleNavItem {
    key: ConsoleView;
    label: string;
    href: string;
    /** One line, for the sidebar. What the screen is for, not what it contains. */
    caption: string;
    /** 16x16 path data, stroked in currentColor. */
    icon: string;
    queue?: ConsoleQueue;
}

const VIEWS: ConsoleNavItem[] = [
    {
        key: 'coverage',
        label: 'Coverage',
        href: '/console/coverage',
        caption: 'The ground, and who holds it',
        // The H3 cell, which is the unit the whole console counts in.
        icon: 'M8 1.6 13.4 4.8v6.4L8 14.4 2.6 11.2V4.8z',
    },
    {
        key: 'review',
        label: 'Review',
        href: '/console/review',
        caption: 'Waiting on a decision',
        // A record with a mark against it.
        icon: 'M2.6 3.4h7m-7 3h7m-7 3h4M10.8 11.2l1.6 1.6 2.8-3.4',
        queue: 'review',
    },
    {
        key: 'claims',
        label: 'Claims',
        href: '/console/claims',
        caption: 'Who owns which listing',
        // A document with a seal on it.
        icon: 'M3.4 1.8h6l3.2 3.2v9.2H3.4zM9.2 1.8V5h3.4M6.4 11.4a1.8 1.8 0 1 0 3.6 0 1.8 1.8 0 1 0-3.6 0',
        queue: 'claims',
    },
    {
        key: 'corrections',
        label: 'Corrections',
        href: '/console/corrections',
        caption: 'What a business says we got wrong',
        // A record, and a mark against one line of it.
        icon: 'M2.8 3h7.4m-7.4 3h5m-5 3h4M11 9.6l1.4 1.4 2.4-3M2.8 12h3',
        queue: 'corrections',
    },
    {
        key: 'orders',
        label: 'Verifications',
        href: '/console/orders',
        caption: 'Paid, and owed a visit',
        // A mark of value, and the promise around it.
        icon: 'M8 2.4a5.6 5.6 0 1 0 0 11.2A5.6 5.6 0 1 0 8 2.4M8 5.2v3.4l2.2 1.4',
        queue: 'orders',
    },
    {
        key: 'live',
        label: 'Live',
        href: '/console/live',
        caption: 'Who is out right now',
        // A fix, and the accuracy around it.
        icon: 'M8 6.6a1.4 1.4 0 1 0 0 2.8 1.4 1.4 0 1 0 0-2.8M4.4 4.4a5 5 0 0 0 0 7.2M11.6 4.4a5 5 0 0 1 0 7.2',
    },
    {
        key: 'exports',
        label: 'Exports',
        href: '/console/exports',
        caption: 'Evidence a client can hold',
        // Out of the system, onto something.
        icon: 'M8 2.2v7.2m0 0L5.4 6.8M8 9.4l2.6-2.6M2.8 11.4v2.4h10.4v-2.4',
    },
];

/**
 * The in-house views, which only an administrator is shown.
 *
 * A second group rather than four more rows in the first. A supervisor runs a
 * mandate; an admin rules on escalations raised by supervisors, adds people and
 * reads the whole log. Presenting them as one list would suggest they are the
 * same job, and the separation is the point: the person who raised a concern
 * about an officer is deliberately not the person who settles it.
 */
const ADMIN_VIEWS: ConsoleNavItem[] = [
    {
        key: 'escalations',
        label: 'Escalations',
        href: '/admin/escalations',
        caption: 'Raised, not yet ruled on',
        // A record lifted out of the stack.
        icon: 'M2.6 12.4h7m-7-3h4M8 7.2V1.8m0 0L5.6 4.2M8 1.8l2.4 2.4m1.4 5.6h2.6',
        queue: 'escalations',
    },
    {
        key: 'audit',
        label: 'Audit log',
        href: '/admin/audit',
        caption: 'Everything that happened',
        // A ledger line, appended to.
        icon: 'M3 2.4h10v11.2H3zM5.4 5.4h5.2M5.4 8h5.2M5.4 10.6h3',
    },
    {
        key: 'people',
        label: 'People',
        href: '/admin/people',
        caption: 'Staff, and their handsets',
        // A person, and a second behind them.
        icon: 'M6 7.4a2.1 2.1 0 1 0 0-4.2 2.1 1 0 1 0 0 4.2M2.4 13.2c0-2.2 1.6-3.6 3.6-3.6s3.6 1.4 3.6 3.6M10.8 3.6a2.1 2.1 0 0 1 0 4.1M11.4 9.8c1.4.3 2.2 1.5 2.2 3.4',
    },
    {
        key: 'campaigns',
        label: 'Campaigns',
        href: '/admin/campaigns',
        caption: 'What clients commissioned',
        // A contract, and the ground it covers.
        icon: 'M3.6 2.2h8.8v11.6H3.6zM6 5.2h4M6 7.6h4M6 10h2.4',
    },
    {
        key: 'mandates',
        label: 'Mandates',
        href: '/admin/mandates',
        caption: 'Ground under contract',
        // A boundary, with a line inside it.
        icon: 'M2.4 4 8 2.2 13.6 4v6.4L8 13.8 2.4 10.4zM8 2.2v11.6',
    },
];

export function consoleNav(): ConsoleNavItem[] {
    return VIEWS;
}

export function adminNav(): ConsoleNavItem[] {
    return ADMIN_VIEWS;
}
