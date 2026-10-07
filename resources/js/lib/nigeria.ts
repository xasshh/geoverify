/**
 * Nigeria's 36 states and the FCT as a tile map: one square per state, laid out
 * roughly where it sits, so a coverage picture reads without a basemap.
 *
 * Codes are the OCHA pcodes coverage_areas.state_code holds (NG015 is the FCT,
 * NG007 Benue), so a mandate lights its state with no lookup table elsewhere.
 */
export interface StateTile {
    code: string;
    abbr: string;
    name: string;
    row: number;
    col: number;
}

export const STATE_TILES: StateTile[] = [
    { code: 'NG034', abbr: 'SO', name: 'Sokoto', row: 0, col: 1 },
    { code: 'NG037', abbr: 'ZA', name: 'Zamfara', row: 0, col: 2 },
    { code: 'NG021', abbr: 'KT', name: 'Katsina', row: 0, col: 3 },
    { code: 'NG020', abbr: 'KN', name: 'Kano', row: 0, col: 4 },
    { code: 'NG018', abbr: 'JI', name: 'Jigawa', row: 0, col: 5 },
    { code: 'NG036', abbr: 'YO', name: 'Yobe', row: 0, col: 6 },
    { code: 'NG008', abbr: 'BO', name: 'Borno', row: 0, col: 7 },
    { code: 'NG022', abbr: 'KE', name: 'Kebbi', row: 1, col: 1 },
    { code: 'NG027', abbr: 'NI', name: 'Niger', row: 1, col: 2 },
    { code: 'NG019', abbr: 'KD', name: 'Kaduna', row: 1, col: 3 },
    { code: 'NG005', abbr: 'BA', name: 'Bauchi', row: 1, col: 4 },
    { code: 'NG016', abbr: 'GO', name: 'Gombe', row: 1, col: 5 },
    { code: 'NG002', abbr: 'AD', name: 'Adamawa', row: 1, col: 6 },
    { code: 'NG024', abbr: 'KW', name: 'Kwara', row: 2, col: 1 },
    { code: 'NG015', abbr: 'FC', name: 'FCT', row: 2, col: 2 },
    { code: 'NG026', abbr: 'NA', name: 'Nasarawa', row: 2, col: 3 },
    { code: 'NG032', abbr: 'PL', name: 'Plateau', row: 2, col: 4 },
    { code: 'NG035', abbr: 'TA', name: 'Taraba', row: 2, col: 5 },
    { code: 'NG031', abbr: 'OY', name: 'Oyo', row: 3, col: 0 },
    { code: 'NG030', abbr: 'OS', name: 'Osun', row: 3, col: 1 },
    { code: 'NG013', abbr: 'EK', name: 'Ekiti', row: 3, col: 2 },
    { code: 'NG023', abbr: 'KO', name: 'Kogi', row: 3, col: 3 },
    { code: 'NG007', abbr: 'BE', name: 'Benue', row: 3, col: 4 },
    { code: 'NG028', abbr: 'OG', name: 'Ogun', row: 4, col: 0 },
    { code: 'NG029', abbr: 'ON', name: 'Ondo', row: 4, col: 1 },
    { code: 'NG012', abbr: 'ED', name: 'Edo', row: 4, col: 2 },
    { code: 'NG014', abbr: 'EN', name: 'Enugu', row: 4, col: 3 },
    { code: 'NG011', abbr: 'EB', name: 'Ebonyi', row: 4, col: 4 },
    { code: 'NG025', abbr: 'LA', name: 'Lagos', row: 5, col: 0 },
    { code: 'NG010', abbr: 'DE', name: 'Delta', row: 5, col: 1 },
    { code: 'NG004', abbr: 'AN', name: 'Anambra', row: 5, col: 2 },
    { code: 'NG017', abbr: 'IM', name: 'Imo', row: 5, col: 3 },
    { code: 'NG001', abbr: 'AB', name: 'Abia', row: 5, col: 4 },
    { code: 'NG009', abbr: 'CR', name: 'Cross River', row: 5, col: 5 },
    { code: 'NG006', abbr: 'BY', name: 'Bayelsa', row: 6, col: 1 },
    { code: 'NG033', abbr: 'RI', name: 'Rivers', row: 6, col: 2 },
    { code: 'NG003', abbr: 'AK', name: 'Akwa Ibom', row: 6, col: 3 },
];
