/** Whole naira, formatted one way everywhere a product order is shown. */
export function naira(amount: number): string {
    return `₦${amount.toLocaleString('en-NG')}`;
}

/** "25 Sep · 9:14 am", or nothing for a step that has not happened. */
export function stamp(iso: string | null): string {
    if (iso === null) {
        return '';
    }

    const d = new Date(iso);

    return `${d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })} · ${d.toLocaleTimeString('en-GB', { hour: 'numeric', minute: '2-digit' })}`;
}
