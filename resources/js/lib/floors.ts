/**
 * Storey naming, in the convention this register records in.
 *
 * Ground is 0, first is 1, a basement is negative. That is the British usage
 * Nigeria follows, and it is the reason the column is signed: a floor index is
 * directly comparable to a building's storey count, so "the third floor of a two
 * storey building" is arithmetic rather than a matter of interpretation.
 *
 * Kept in one place because the field client asks the question and the console
 * draws the answer, and two spellings of "Ground" would read as two floors.
 */

const ORDINALS = [
    'Ground',
    'First',
    'Second',
    'Third',
    'Fourth',
    'Fifth',
    'Sixth',
    'Seventh',
    'Eighth',
    'Ninth',
    'Tenth',
];

/** The storey, written the way an officer would say it out loud. */
export function floorName(floor: number): string {
    if (floor < 0) {
        return floor === -1 ? 'Basement' : `Basement ${String(Math.abs(floor))}`;
    }

    if (floor === 0) {
        return 'Ground floor';
    }

    const ordinal = ORDINALS[floor];

    return ordinal === undefined ? `Floor ${String(floor)}` : `${ordinal} floor`;
}

/**
 * The same storey in the width of a table column.
 *
 * G rather than 0, because a column of floor numbers where the ground floor
 * reads as zero invites somebody to total it.
 */
export function floorShort(floor: number): string {
    if (floor < 0) {
        return `B${String(Math.abs(floor))}`;
    }

    return floor === 0 ? 'G' : String(floor);
}

/**
 * The storeys a business in this building could be on.
 *
 * One basement is offered whatever the storey count says, because `floors`
 * counts upwards from the ground and a basement sits outside that count rather
 * than at the bottom of it. Everything else comes from what the officer recorded
 * about the building, so the choices cannot contradict it.
 */
export function floorOptions(storeys: number | null): Array<{ value: number; label: string }> {
    const above = Math.max(1, storeys ?? 1);
    const options = [{ value: -1, label: floorName(-1) }];

    for (let floor = 0; floor < above; floor += 1) {
        options.push({ value: floor, label: floorName(floor) });
    }

    return options;
}
