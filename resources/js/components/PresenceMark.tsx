import { useId, useMemo } from 'react';
import { cx } from '@/lib/cx';

export type TracePoint = readonly [number, number];

interface PresenceMarkProps {
    /**
     * The officer's recorded positions, in any consistent coordinate space.
     * In production these come from the field session's LineStringM.
     */
    points: readonly TracePoint[];
    size: number;
    /** Gold is the default. Green and alert are used only to teach the contrast. */
    tone?: 'gold' | 'green' | 'alert';
    /** The capture position. Suppressed below 40px, where it would be a blob. */
    showCapturePoint?: boolean;
    /** Draws the trace along its own timestamps. Review screen only. */
    animate?: boolean;
    label?: string;
}

const HEX_RADIUS = 44;

function hexPath(radius: number): string {
    const points: string[] = [];

    for (let i = 0; i < 6; i += 1) {
        const angle = (Math.PI / 180) * (60 * i - 30);
        points.push(
            `${(50 + radius * Math.cos(angle)).toFixed(2)},${(50 + radius * Math.sin(angle)).toFixed(2)}`,
        );
    }

    return `M${points.join('L')}Z`;
}

/**
 * Scales a trace to fill the glyph.
 *
 * Without this the mark reads as a smudge in one corner at small sizes, which
 * defeats the point: at 16px the mark has to carry density, so a walked day and
 * a fabricated one are told apart in a queue column without opening either.
 * Scaling stays uniform, so the shape of the walk is never distorted.
 */
function fitToGlyph(points: readonly TracePoint[], radius: number): TracePoint[] {
    if (points.length === 0) {
        return [];
    }

    const xs = points.map(([x]) => x);
    const ys = points.map(([, y]) => y);
    const minX = Math.min(...xs);
    const maxX = Math.max(...xs);
    const minY = Math.min(...ys);
    const maxY = Math.max(...ys);

    const width = Math.max(maxX - minX, 1e-6);
    const height = Math.max(maxY - minY, 1e-6);
    const scale = Math.min((radius * 2) / width, (radius * 2) / height);
    const centreX = (minX + maxX) / 2;
    const centreY = (minY + maxY) / 2;

    return points.map(([x, y]) => [50 + (x - centreX) * scale, 50 + (y - centreY) * scale]);
}

const TONE_STROKE: Record<NonNullable<PresenceMarkProps['tone']>, string> = {
    gold: 'stroke-gold',
    green: 'stroke-green',
    alert: 'stroke-alert',
};

const TONE_FILL: Record<NonNullable<PresenceMarkProps['tone']>, string> = {
    gold: 'fill-gold',
    green: 'fill-green',
    alert: 'fill-alert',
};

/**
 * The Presence Mark.
 *
 * A hexagon holding the officer's actual walking trace at the moment of capture,
 * with a dot at the capture position. Generated, never designed: the same trace
 * always produces the same mark, and no two walks are alike.
 *
 * It is also the anti-spoofing affordance. Trace naturalness is a scored signal
 * server-side; this makes the same fact pre-attentive, so a fabricated day stands
 * out in a column of two hundred.
 */
export function PresenceMark({
    points,
    size,
    tone = 'gold',
    showCapturePoint = true,
    animate = false,
    label,
}: PresenceMarkProps) {
    const titleId = useId();
    const fitted = useMemo(
        () => fitToGlyph(points, size < 24 ? 34 : 31),
        [points, size],
    );

    const path = useMemo(
        () => (fitted.length > 0 ? `M${fitted.map(([x, y]) => `${x.toFixed(2)},${y.toFixed(2)}`).join('L')}` : ''),
        [fitted],
    );

    const last = fitted.at(-1);
    const withDot = showCapturePoint && size >= 40 && last !== undefined;
    // Small marks need proportionally heavier strokes or they read as smudges in a
    // queue column, which is the one place the mark has to work hardest.
    const strokeWidth = size < 24 ? 3.4 : size < 48 ? 3 : size < 96 ? 2.2 : 1.8;

    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 100 100"
            role="img"
            aria-labelledby={titleId}
            className="shrink-0 overflow-visible"
        >
            <title id={titleId}>
                {label ?? `Officer trace within the assigned cell, ${String(points.length)} recorded fixes`}
            </title>

            {/* The cell. The work is bounded by it, so the mark is bounded by it. */}
            <path
                d={hexPath(HEX_RADIUS)}
                fill="none"
                className="stroke-graphite"
                strokeWidth={size < 24 ? 2.6 : 1.5}
                strokeOpacity={0.35}
            />

            {path !== '' && (
                <path
                    d={path}
                    fill="none"
                    className={cx(
                        TONE_STROKE[tone],
                        animate && 'gv-trace-draw motion-reduce:[animation:none]',
                    )}
                    strokeWidth={strokeWidth}
                    strokeLinejoin="round"
                    strokeLinecap="round"
                    pathLength={animate ? 1 : undefined}
                />
            )}

            {withDot && (
                <circle
                    cx={last[0]}
                    cy={last[1]}
                    r={size < 60 ? 4.2 : 3.2}
                    className={TONE_FILL[tone]}
                />
            )}
        </svg>
    );
}
