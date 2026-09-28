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
    | 'team'
    | 'inspections'
    | 'messages'
    | 'brief'
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
    | 'campaigns'
    | 'investors'
    | 'disputes'
    | 'reviews';

/** Which waiting count belongs against a view, where one does. */
export type ConsoleQueue = 'review' | 'claims' | 'corrections' | 'escalations' | 'orders' | 'messages' | 'inspections';

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

/*
 * The supervisor's views, in the enumeration mockup's order: the team first,
 * then the map, the queue and the inbox, then the ground and the paperwork.
 * Registry work that is not enumeration (claims, corrections, paid visits)
 * follows as its own group, so it keeps its counts without crowding the day.
 */
const VIEWS: ConsoleNavItem[] = [
    {
        key: 'team',
        label: 'Team today',
        href: '/console',
        caption: 'Who is out, and what is waiting',
        // A roof: the day starts here.
        icon: 'M2.4 7.4 8 2.6l5.6 4.8M3.8 6.4v7h8.4v-7',
    },
    {
        key: 'live',
        label: 'Live map',
        href: '/console/live',
        caption: 'Who is out right now',
        icon: 'M8 6.6a1.4 1.4 0 1 0 0 2.8 1.4 1.4 0 1 0 0-2.8M4.4 4.4a5 5 0 0 0 0 7.2M11.6 4.4a5 5 0 0 1 0 7.2',
    },
    {
        key: 'review',
        label: 'QA review',
        href: '/console/review',
        caption: 'Waiting on a decision',
        icon: 'M2.6 3.4h7m-7 3h7m-7 3h4M10.8 11.2l1.6 1.6 2.8-3.4',
        queue: 'review',
    },
    {
        key: 'inspections',
        label: 'Inspections & visits',
        href: '/console/inspections',
        caption: 'Paid for, waiting for an agent',
        // A magnifier over a box.
        icon: 'M2.6 5.6h7.2v7.2H2.6zM11.4 9.8a2 2 0 1 0 0-4 2 2 0 0 0 0 4M12.8 9.2l1.6 1.6',
        queue: 'inspections',
    },
    {
        key: 'messages',
        label: 'Messages',
        href: '/console/messages',
        caption: 'Officers, and what they said',
        // A speech box.
        icon: 'M2.6 3h10.8v7.4H6.4L3.4 13v-2.6h-.8z',
        queue: 'messages',
    },
    {
        key: 'coverage',
        label: 'Cell assignments',
        href: '/console/coverage',
        caption: 'The ground, and who holds it',
        icon: 'M8 1.6 13.4 4.8v6.4L8 14.4 2.6 11.2V4.8z',
    },
    {
        key: 'brief',
        label: 'Campaign brief',
        href: '/console/brief',
        caption: 'What the client commissioned',
        icon: 'M3.4 1.8h6l3.2 3.2v9.2H3.4zM5.8 8h4.4M5.8 10.6h4.4',
    },
    {
        key: 'exports',
        label: 'Reports',
        href: '/console/exports',
        caption: 'Evidence a client can hold',
        icon: 'M3 13.4V8.6M6.4 13.4V4.6M9.8 13.4V7M13.2 13.4V2.6',
    },
];

/** Registry work beside enumeration: ownership, corrections and paid visits. */
const REGISTRY_VIEWS: ConsoleNavItem[] = [
    {
        key: 'claims',
        label: 'Claims',
        href: '/console/claims',
        caption: 'Who owns which listing',
        icon: 'M3.4 1.8h6l3.2 3.2v9.2H3.4zM9.2 1.8V5h3.4M6.4 11.4a1.8 1.8 0 1 0 3.6 0 1.8 1.8 0 1 0-3.6 0',
        queue: 'claims',
    },
    {
        key: 'corrections',
        label: 'Corrections',
        href: '/console/corrections',
        caption: 'What a business says we got wrong',
        icon: 'M2.8 3h7.4m-7.4 3h5m-5 3h4M11 9.6l1.4 1.4 2.4-3M2.8 12h3',
        queue: 'corrections',
    },
    {
        key: 'orders',
        label: 'Verifications',
        href: '/console/orders',
        caption: 'Paid, and owed a visit',
        icon: 'M8 2.4a5.6 5.6 0 1 0 0 11.2A5.6 5.6 0 1 0 8 2.4M8 5.2v3.4l2.2 1.4',
        queue: 'orders',
    },
];

export function registryNav(): ConsoleNavItem[] {
    return REGISTRY_VIEWS;
}

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
        key: 'investors',
        label: 'Investors',
        href: '/admin/investors',
        caption: 'Organisations awaiting KYC',
        // A briefcase.
        icon: 'M2.4 5.2h11.2v7.6H2.4zM5.8 5.2V3.4h4.4v1.8M2.4 8.4h11.2',
    },
    {
        key: 'disputes',
        label: 'Disputes',
        href: '/admin/disputes',
        caption: 'Buyer issues, money held',
        // Two sides of a scale.
        icon: 'M8 2.4v11.2M4.4 13.6h7.2M2.4 4.4h11.2M4 4.4 2.4 8.4h3.2zM12 4.4l-1.6 4h3.2z',
    },
    {
        key: 'reviews',
        label: 'Reviews',
        href: '/admin/reviews',
        caption: 'What buyers said, reported first',
        // A star.
        icon: 'M8 1.8l1.9 3.9 4.3.6-3.1 3 .7 4.3L8 11.6l-3.8 2 .7-4.3-3.1-3 4.3-.6z',
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
