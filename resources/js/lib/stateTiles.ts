/**
 * Nigeria as a tile grid: one square per state, placed roughly where it sits.
 *
 * A tile map rather than a choropleth because a choropleth gives Borno thirty
 * times the ink of Lagos and makes area look like importance. Every state gets
 * the same square, and the shade carries the count.
 */
export const STATE_TILES: { code: string; name: string; row: number; col: number }[] = [
    { code: 'SO', name: 'Sokoto', row: 0, col: 1 },
    { code: 'ZA', name: 'Zamfara', row: 0, col: 2 },
    { code: 'KT', name: 'Katsina', row: 0, col: 3 },
    { code: 'KN', name: 'Kano', row: 0, col: 4 },
    { code: 'JI', name: 'Jigawa', row: 0, col: 5 },
    { code: 'YO', name: 'Yobe', row: 0, col: 6 },
    { code: 'BO', name: 'Borno', row: 0, col: 7 },
    { code: 'KE', name: 'Kebbi', row: 1, col: 1 },
    { code: 'NI', name: 'Niger', row: 1, col: 2 },
    { code: 'KD', name: 'Kaduna', row: 1, col: 3 },
    { code: 'BA', name: 'Bauchi', row: 1, col: 4 },
    { code: 'GO', name: 'Gombe', row: 1, col: 5 },
    { code: 'AD', name: 'Adamawa', row: 1, col: 6 },
    { code: 'KW', name: 'Kwara', row: 2, col: 1 },
    { code: 'FC', name: 'Federal Capital Territory', row: 2, col: 2 },
    { code: 'NA', name: 'Nasarawa', row: 2, col: 3 },
    { code: 'PL', name: 'Plateau', row: 2, col: 4 },
    { code: 'TA', name: 'Taraba', row: 2, col: 5 },
    { code: 'OY', name: 'Oyo', row: 3, col: 0 },
    { code: 'OS', name: 'Osun', row: 3, col: 1 },
    { code: 'EK', name: 'Ekiti', row: 3, col: 2 },
    { code: 'KO', name: 'Kogi', row: 3, col: 3 },
    { code: 'BE', name: 'Benue', row: 3, col: 4 },
    { code: 'OG', name: 'Ogun', row: 4, col: 0 },
    { code: 'ON', name: 'Ondo', row: 4, col: 1 },
    { code: 'ED', name: 'Edo', row: 4, col: 2 },
    { code: 'EN', name: 'Enugu', row: 4, col: 3 },
    { code: 'EB', name: 'Ebonyi', row: 4, col: 4 },
    { code: 'CR', name: 'Cross River', row: 4, col: 5 },
    { code: 'LA', name: 'Lagos', row: 5, col: 0 },
    { code: 'DE', name: 'Delta', row: 5, col: 1 },
    { code: 'AN', name: 'Anambra', row: 5, col: 2 },
    { code: 'IM', name: 'Imo', row: 5, col: 3 },
    { code: 'AB', name: 'Abia', row: 5, col: 4 },
    { code: 'AK', name: 'Akwa Ibom', row: 5, col: 5 },
    { code: 'BY', name: 'Bayelsa', row: 6, col: 1 },
    { code: 'RI', name: 'Rivers', row: 6, col: 2 },
];
