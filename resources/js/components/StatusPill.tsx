import { cx } from '@/lib/cx';
import { STATUS_COLOR, STATUS_SHAPE, type StatusShape, type StatusTone } from '@/lib/status';

interface StatusGlyphProps {
    shape: StatusShape;
    size?: number;
}

/**
 * The shape half of a status. Rendered as a filled mark so it survives greyscale
 * printing in an evidence pack, where hue carries nothing at all.
 */
export function StatusGlyph({ shape, size = 9 }: StatusGlyphProps) {
    const common = { fill: 'currentColor' } as const;

    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 10 10"
            aria-hidden="true"
            className="shrink-0"
        >
            {shape === 'circle' && <circle cx="5" cy="5" r="4" {...common} />}
            {shape === 'triangle' && <path d="M5 0.4 9.6 9.2H0.4z" {...common} />}
            {shape === 'diamond' && <path d="M5 0.4 9.6 5 5 9.6 0.4 5z" {...common} />}
            {shape === 'square' && <rect x="1" y="1" width="8" height="8" {...common} />}
            {shape === 'ring' && (
                <circle cx="5" cy="5" r="3.6" fill="none" stroke="currentColor" strokeWidth="1.7" />
            )}
            {/* Half filled: taken, not yet earned. Reads as a circle part way
                to being one, which is what held money is. */}
            {shape === 'half' && (
                <>
                    <circle cx="5" cy="5" r="3.6" fill="none" stroke="currentColor" strokeWidth="1.7" />
                    <path d="M5 1.4a3.6 3.6 0 0 1 0 7.2z" {...common} />
                </>
            )}
        </svg>
    );
}

interface StatusPillProps {
    tone: StatusTone;
    label: string;
    /** Filled reads louder. Use it for the one status a screen is about. */
    emphasis?: 'outline' | 'filled';
    size?: 'sm' | 'md';
}

export function StatusPill({ tone, label, emphasis = 'outline', size = 'md' }: StatusPillProps) {
    const shape = STATUS_SHAPE[tone];
    const color = STATUS_COLOR[tone];

    const filledBackground: Record<StatusTone, string> = {
        accepted: 'bg-green',
        review: 'bg-amber',
        rejected: 'bg-alert',
        progress: 'bg-gold',
        idle: 'bg-graphite',
        held: 'bg-held',
    };

    return (
        <span
            className={cx(
                'inline-flex items-center gap-2 rounded-sm border font-semibold whitespace-nowrap',
                size === 'sm' ? 'px-2 py-0.5 text-label' : 'px-2.5 py-1 text-ui',
                emphasis === 'filled'
                    ? cx(filledBackground[tone], 'text-on-accent border-transparent')
                    : cx(color, 'border-current/45'),
            )}
        >
            <StatusGlyph shape={shape} size={size === 'sm' ? 8 : 9} />
            {label}
        </span>
    );
}
