import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { cx } from '@/lib/cx';

type Variant = 'primary' | 'secondary' | 'quiet' | 'destructive';

/**
 * console        32px, for a mouse and a keyboard in a register room.
 * field-compact   44px, inline row actions on a field surface.
 * field           48px, ordinary field actions and anything destructive.
 * field-primary   52px, the one action a field screen is about.
 *
 * The field sizes are floors from the brief, not suggestions: one-handed, gloved,
 * in sunlight.
 */
type Size = 'console' | 'field-compact' | 'field' | 'field-primary';

interface ButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'className'> {
    variant?: Variant;
    size?: Size;
    /** Shows progress and blocks repeat presses. The label stays readable. */
    busy?: boolean;
    fullWidth?: boolean;
    children: ReactNode;
}

const VARIANT: Record<Variant, string> = {
    // Gold means verification and action. It is the only accent on the screen.
    primary: 'bg-gold text-on-accent border-transparent hover:opacity-90 active:opacity-80',
    secondary: 'bg-transparent text-ink border-rule-strong hover:bg-raised active:bg-sunken',
    quiet: 'bg-transparent text-muted border-transparent hover:bg-raised hover:text-ink',
    destructive: 'bg-transparent text-alert border-current/50 hover:bg-alert hover:text-on-accent',
};

const SIZE: Record<Size, string> = {
    console: 'h-8 px-3 text-ui gap-2',
    // Inline row actions on a field surface. 44px is the absolute floor.
    'field-compact': 'min-h-touch px-4 text-ui gap-2',
    field: 'min-h-touch-lg px-5 text-body gap-2.5',
    'field-primary': 'min-h-touch-xl px-6 text-body font-semibold gap-2.5',
};

export function Button({
    variant = 'secondary',
    size = 'console',
    busy = false,
    fullWidth = false,
    disabled,
    children,
    ...rest
}: ButtonProps) {
    const isDisabled = disabled === true || busy;

    return (
        <button
            type="button"
            {...rest}
            disabled={isDisabled}
            aria-busy={busy || undefined}
            className={cx(
                'inline-flex items-center justify-center rounded-sm border font-medium',
                'transition-[opacity,background-color,color] duration-150',
                'disabled:cursor-not-allowed disabled:opacity-45',
                VARIANT[variant],
                SIZE[size],
                fullWidth && 'w-full',
            )}
        >
            {busy && (
                <svg
                    className="size-4 animate-spin motion-reduce:animate-none"
                    viewBox="0 0 16 16"
                    aria-hidden="true"
                >
                    <circle
                        cx="8"
                        cy="8"
                        r="6.5"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2"
                        strokeOpacity="0.3"
                    />
                    <path
                        d="M8 1.5a6.5 6.5 0 0 1 6.5 6.5"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2"
                        strokeLinecap="round"
                    />
                </svg>
            )}
            {children}
        </button>
    );
}
