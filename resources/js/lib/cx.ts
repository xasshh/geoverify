/**
 * Joins class names, dropping anything falsy. Deliberately tiny: the design
 * system has no need for variant-merging machinery, and a dependency here would
 * ship to every field device.
 */
export function cx(...parts: Array<string | false | null | undefined>): string {
    return parts.filter(Boolean).join(' ');
}
