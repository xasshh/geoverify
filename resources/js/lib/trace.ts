import type { TracePoint } from '@/components/PresenceMark';

/**
 * Sample trace generation, for the design gallery and for tests.
 *
 * Not used in production: real traces come from the field session's LineStringM.
 * Seeded so a given seed always produces the same walk, which keeps the design
 * gallery stable across reloads and makes screenshot diffs meaningful.
 */
function seeded(seed: number): () => number {
    let state = seed >>> 0;

    return () => {
        state ^= state << 13;
        state >>>= 0;
        state ^= state >> 17;
        state ^= state << 5;
        state >>>= 0;

        return state / 4294967296;
    };
}

/**
 * A plausible enumeration walk: cardinal street legs, ninety degree turns at
 * corners, a cluster of near-identical fixes wherever the officer stopped to
 * record an enterprise, and per-fix jitter from ordinary GNSS noise.
 */
export function walkedTrace(seed: number, legs = 11): TracePoint[] {
    const random = seeded(seed);
    const points: TracePoint[] = [];
    const dx = [1, 0, -1, 0];
    const dy = [0, 1, 0, -1];

    let x = 50;
    let y = 50;
    let heading = Math.floor(random() * 4);

    for (let leg = 0; leg < legs; leg += 1) {
        const steps = 3 + Math.floor(random() * 6);

        for (let step = 0; step < steps; step += 1) {
            x += (dx[heading] ?? 0) * (2.4 + random() * 1.6);
            y += (dy[heading] ?? 0) * (2.4 + random() * 1.6);

            if (x < 10 || x > 90) {
                heading = (heading + 2) % 4;
                x = Math.max(10, Math.min(90, x));
            }
            if (y < 10 || y > 90) {
                heading = (heading + 2) % 4;
                y = Math.max(10, Math.min(90, y));
            }

            points.push([x + (random() - 0.5) * 1.4, y + (random() - 0.5) * 1.4]);
        }

        // A dwell: the officer stopped here and captured something.
        if (random() < 0.3) {
            for (let k = 0; k < 4; k += 1) {
                points.push([x + (random() - 0.5) * 2.2, y + (random() - 0.5) * 2.2]);
            }
        }

        heading = (heading + (random() < 0.5 ? 1 : 3)) % 4;
    }

    return points;
}

/**
 * What a mock location app produces: straight segments, uniform spacing, no
 * dwell anywhere, and no jitter at all. Nobody walks like this.
 */
export function fabricatedTrace(fixes = 14): TracePoint[] {
    return Array.from({ length: fixes }, (_, i) => {
        const t = i / (fixes - 1);

        return [20 + t * 60, 74 - t * 46] as TracePoint;
    });
}
