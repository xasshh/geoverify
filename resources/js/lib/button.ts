import { cx } from '@/lib/cx';

export type Variant = 'primary' | 'secondary' | 'soft' | 'quiet' | 'destructive';
/**
 * console        36px, for a mouse and a keyboard in a register room.
 * field-compact   44px, inline row actions on a field surface.
 * field           48px, ordinary field actions and anything destructive.
 * field-primary   52px, the one action a field screen is about.
 *
 * The field sizes are floors from the brief, not suggestions: one-handed, gloved,
 * in sunlight.
 */
export type Size = 'console' | 'field-compact' | 'field' | 'field-primary';

const VARIANT: Record<Variant, string> = {
    // The accent means verification and action. It is the only one on the screen.
    primary: 'bg-gold text-on-accent border-transparent font-extrabold hover:bg-gold-dark active:bg-gold-dark',
    secondary: 'bg-raised text-ink border-rule-strong font-bold hover:bg-sunken active:bg-sunken',
    soft: 'bg-gold-soft text-gold-dark border-transparent font-bold hover:brightness-95',
    quiet: 'bg-transparent text-muted border-transparent font-semibold hover:bg-sunken hover:text-ink',
    destructive: 'bg-raised text-alert-ink border-current/40 font-bold hover:bg-alert hover:text-on-accent',
};

const SIZE: Record<Size, string> = {
    console: 'h-9 px-3.5 text-ui gap-2',
    // Inline row actions on a field surface. 44px is the absolute floor.
    'field-compact': 'min-h-touch px-4 text-ui gap-2',
    field: 'min-h-touch-lg px-5 text-body gap-2.5',
    'field-primary': 'min-h-touch-xl px-6 text-body gap-2.5',
};

/**
 * The button's dressing, without the button.
 *
 * A navigation is an anchor: it middle-clicks, it opens in a new tab, it says
 * "link" to a screen reader, and Inertia's Link is the right element for it.
 * What it still needs is to look like the primary action, so the classes are
 * shared rather than the element. See buttonClass.
 */
export function buttonClass(
    variant: Variant = 'secondary',
    size: Size = 'console',
    fullWidth = false,
): string {
    return cx(
        'inline-flex items-center justify-center rounded-sm border',
        'transition-[opacity,background-color,color,filter] duration-150',
        VARIANT[variant],
        SIZE[size],
        fullWidth && 'w-full',
    );
}


