/**
 * The console's four working views, now five.
 *
 * Collected here because the bar was built by repeating the same array on every
 * page, and adding a view meant editing all of them and hoping none was missed.
 * A link that exists on four screens out of five reads as a bug in the one that
 * lacks it.
 */
export type ConsoleView = "coverage" | "review" | "claims" | "live" | "exports";

const VIEWS: Array<{ key: ConsoleView; label: string; href: string }> = [
    { key: "coverage", label: "Coverage", href: "/console/coverage" },
    { key: "review", label: "Review", href: "/console/review" },
    { key: "claims", label: "Claims", href: "/console/claims" },
    { key: "live", label: "Live", href: "/console/live" },
    { key: "exports", label: "Exports", href: "/console/exports" },
];

export function consoleLinks(current: ConsoleView | null) {
    return VIEWS.map((view) => ({
        label: view.label,
        href: view.href,
        current: view.key === current,
    }));
}
