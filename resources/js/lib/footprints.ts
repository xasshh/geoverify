import type { ExpressionSpecification, Map as MapLibre, SymbolLayerSpecification } from 'maplibre-gl';

/**
 * A pair of footprints, drawn once on a canvas and stamped along an officer's
 * path in place of a line, so a trace reads as somebody walking.
 *
 * Drawn rather than loaded: the field map runs offline from a precached
 * bundle, and an image fetched at runtime is one more thing that can fail in a
 * building with no signal. The prints point along +x; MapLibre turns each
 * stamp to face the direction of travel when the symbol is placed on a line.
 */
const W = 44;
const H = 30;
const RATIO = 2;

/**
 * One print pointing along +x: a heel, a gap for the arch, the ball of the
 * foot, and five toes with the big toe on the inside. `flip` mirrors it so the
 * pair reads as a left foot and a right foot.
 */
function foot(ctx: CanvasRenderingContext2D, x: number, y: number, flip: boolean): void {
    const side = flip ? -1 : 1;
    const blob = (dx: number, dy: number, rx: number, ry: number, rot = 0) => {
        ctx.beginPath();
        ctx.ellipse(x + dx, y + side * dy, rx, ry, side * rot, 0, Math.PI * 2);
        ctx.fill();
    };
    blob(-8.2, 0.5, 3.4, 2.9); // heel
    blob(0.6, -0.3, 4.6, 3.8, -0.15); // ball, leaning towards the big toe
    // Toes, big toe first, on the inside (negative y before the flip).
    const toes: Array<[number, number, number]> = [
        [7.9, -3.1, 1.85],
        [9.2, -0.5, 1.3],
        [9.0, 1.6, 1.15],
        [8.2, 3.4, 1.0],
        [6.9, 4.8, 0.85],
    ];
    for (const [dx, dy, r] of toes) {
        blob(dx, dy, r, r);
    }
}

export function footprintImage(color: string): ImageData | null {
    if (typeof document === 'undefined') return null;
    const canvas = document.createElement('canvas');
    canvas.width = W * RATIO;
    canvas.height = H * RATIO;
    const ctx = canvas.getContext('2d');
    if (ctx === null) return null;
    ctx.scale(RATIO, RATIO);
    ctx.fillStyle = color;
    // Left foot a step back and to one side, right foot ahead on the other.
    foot(ctx, 13, 8.5, true);
    foot(ctx, 31, 21.5, false);

    return ctx.getImageData(0, 0, canvas.width, canvas.height);
}

/** Registers the footprint stamp under `id` once per map, in the given colour. */
export function addFootprintImage(map: MapLibre, id: string, color: string): void {
    if (map.hasImage(id)) return;
    const data = footprintImage(color);
    if (data !== null) map.addImage(id, data, { pixelRatio: RATIO });
}

/** A symbol layer that walks footprints along a line source. */
export function footprintLayer(
    id: string,
    source: string,
    image: string | ExpressionSpecification,
    opacity: number | ExpressionSpecification = 0.9,
): SymbolLayerSpecification {
    return {
        id,
        type: 'symbol',
        source,
        layout: {
            'symbol-placement': 'line',
            'symbol-spacing': ['interpolate', ['linear'], ['zoom'], 14, 18, 18, 30],
            'icon-image': image,
            'icon-size': ['interpolate', ['linear'], ['zoom'], 13, 0.45, 17, 0.75, 20, 1],
            'icon-rotation-alignment': 'map',
            'icon-allow-overlap': true,
            'icon-ignore-placement': true,
        },
        paint: { 'icon-opacity': opacity },
    };
}
