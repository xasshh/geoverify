import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { buttonClass, type Size, type Variant } from '@/lib/button';
import { cx } from '@/lib/cx';

interface ButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'className'> {
    variant?: Variant;
    size?: Size;
    /** Shows progress and blocks repeat presses. The label stays readable. */
    busy?: boolean;
    fullWidth?: boolean;
    children: ReactNode;
}

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
                buttonClass(variant, size, fullWidth),
                'disabled:cursor-not-allowed disabled:opacity-45',
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
