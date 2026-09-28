/**
 * Directions, without our coordinate.
 *
 * CLAUDE.md forbids publishing an exact position, so Directions hands the
 * reader's own map app a search: the business's name, the street address its
 * owner chose to publish if there is one, the ward and the local government.
 * The map app finds the place the way anybody would; we tell it nothing a
 * stranger could not type.
 */
export function directionsUrl(place: { name: string; address?: string | null; ward?: string | null; lga?: string | null }): string {
    const query = [place.name, place.address, place.ward, place.lga, 'Nigeria'].filter((part) => part !== null && part !== undefined && part !== '').join(', ');

    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(query)}`;
}
