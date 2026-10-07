import type { BasemapChoice } from '@/lib/offline/imagery';
import { cx } from '@/lib/cx';

/**
 * Street map or satellite, over the field map.
 *
 * Shown only when the officer has imagery of this mandate on the phone. The
 * date is always beside the satellite option: a farm cleared since the image
 * was taken is not the officer's mistake, and they should not be made to
 * think it is.
 */
export function BasemapSwitch({
    choice,
    captured,
    onChange,
    className,
}: {
    choice: BasemapChoice;
    captured: string | null;
    onChange: (next: BasemapChoice) => void;
    className?: string;
}) {
    return (
        <div
            className={cx(
                'flex flex-col gap-2 rounded-card border border-rule bg-raised/95 p-2 shadow-card backdrop-blur',
                className,
            )}
        >
            <div role="radiogroup" aria-label="Map" className="grid grid-cols-2 gap-1 rounded-sm bg-sunken p-1">
                {(['street', 'satellite'] as const).map((kind) => (
                    <button
                        key={kind}
                        type="button"
                        role="radio"
                        aria-checked={choice.basemap === kind}
                        onClick={() => {
                            onChange({ ...choice, basemap: kind });
                        }}
                        className={cx(
                            'min-h-touch rounded-[6px] px-3 text-ui capitalize',
                            choice.basemap === kind ? 'bg-raised font-extrabold text-ink shadow-card' : 'font-semibold text-muted',
                        )}
                    >
                        {kind}
                    </button>
                ))}
            </div>
            {choice.basemap === 'satellite' && (
                <>
                    <label className="flex items-center gap-2 px-1 text-label text-muted">
                        <span className="shrink-0">See through</span>
                        <input
                            type="range"
                            min={20}
                            max={100}
                            step={10}
                            value={Math.round(choice.opacity * 100)}
                            onChange={(e) => {
                                onChange({ ...choice, opacity: Number(e.target.value) / 100 });
                            }}
                            className="w-full accent-gold"
                            aria-label="Image opacity"
                        />
                    </label>
                    {captured !== null && <p className="px-1 text-label text-faint">Image of {captured}</p>}
                </>
            )}
        </div>
    );
}
