import { useId } from 'react';
import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes } from 'react';
import { cx } from '@/lib/cx';

interface FieldShellProps {
    label: string;
    hint?: string;
    error?: string;
    /** Machine values (coordinates, H3 indices, references) are set in mono. */
    machine?: boolean;
    children: (props: { id: string; describedBy: string | undefined; invalid: boolean }) => ReactNode;
}

/**
 * Label, control, and the one message that matters underneath.
 *
 * An error replaces the hint rather than stacking on it: two lines of guidance
 * under a field in sunlight is one line too many, and the error is what the
 * officer has to act on.
 */
function FieldShell({ label, hint, error, machine = false, children }: FieldShellProps) {
    const id = useId();
    const hintId = `${id}-hint`;
    const invalid = error !== undefined && error !== '';
    const message = invalid ? error : hint;
    const describedBy = message !== undefined && message !== '' ? hintId : undefined;

    return (
        <div className={cx('flex flex-col gap-1.5', machine && 'font-mono')}>
            <label
                htmlFor={id}
                className="text-label font-semibold tracking-[0.12em] text-muted uppercase"
            >
                {label}
            </label>

            {children({ id, describedBy, invalid })}

            {message !== undefined && message !== '' && (
                <p
                    id={hintId}
                    className={cx('text-ui', invalid ? 'text-alert' : 'text-faint')}
                >
                    {invalid && (
                        <span aria-hidden="true" className="mr-1.5 font-semibold">
                            !
                        </span>
                    )}
                    {message}
                </p>
            )}
        </div>
    );
}

const CONTROL = [
    'w-full rounded-sm border bg-surface px-3 text-ink',
    'placeholder:text-faint',
    'disabled:cursor-not-allowed disabled:bg-raised disabled:text-faint',
    '[&[readonly]]:border-dashed [&[readonly]]:bg-raised [&[readonly]]:text-muted',
    'transition-colors duration-150',
].join(' ');

interface TextFieldProps
    extends Omit<InputHTMLAttributes<HTMLInputElement>, 'className' | 'id' | 'size'> {
    label: string;
    hint?: string;
    error?: string;
    machine?: boolean;
    size?: 'console' | 'field';
}

export function TextField({
    label,
    hint,
    error,
    machine = false,
    size = 'console',
    ...rest
}: TextFieldProps) {
    return (
        <FieldShell
            label={label}
            {...(hint === undefined ? {} : { hint })}
            {...(error === undefined ? {} : { error })}
            machine={machine}
        >
            {({ id, describedBy, invalid }) => (
                <input
                    id={id}
                    aria-describedby={describedBy}
                    aria-invalid={invalid || undefined}
                    {...rest}
                    className={cx(
                        CONTROL,
                        size === 'field' ? 'min-h-touch-lg text-body' : 'h-9 text-ui',
                        invalid ? 'border-alert' : 'border-rule-strong focus:border-gold',
                        machine && 'font-mono',
                    )}
                />
            )}
        </FieldShell>
    );
}

interface SelectFieldProps
    extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'className' | 'id' | 'size'> {
    label: string;
    hint?: string;
    error?: string;
    size?: 'console' | 'field';
    children: ReactNode;
}

export function SelectField({
    label,
    hint,
    error,
    size = 'console',
    children,
    ...rest
}: SelectFieldProps) {
    return (
        <FieldShell
            label={label}
            {...(hint === undefined ? {} : { hint })}
            {...(error === undefined ? {} : { error })}
        >
            {({ id, describedBy, invalid }) => (
                <select
                    id={id}
                    aria-describedby={describedBy}
                    aria-invalid={invalid || undefined}
                    {...rest}
                    className={cx(
                        CONTROL,
                        size === 'field' ? 'min-h-touch-lg text-body' : 'h-9 text-ui',
                        invalid ? 'border-alert' : 'border-rule-strong focus:border-gold',
                    )}
                >
                    {children}
                </select>
            )}
        </FieldShell>
    );
}
