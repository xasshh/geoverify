import { useRef, useState } from 'react';
import { holdPhotograph } from '@/lib/offline/queue';
import { cx } from '@/lib/cx';

interface PhotoCaptureProps {
    /** The building's own client uuid, which exists before any server has seen it. */
    structureClientUuid: string;
    position: { longitude: number; latitude: number } | null;
}

const KINDS: Array<{ value: string; label: string; hint: string; required: boolean }> = [
    { value: 'facade', label: 'Front', hint: 'The whole building from the street', required: true },
    { value: 'signage', label: 'Signage', hint: 'Close enough to read the name', required: true },
    { value: 'street_context', label: 'Street', hint: 'What is either side of it', required: true },
    { value: 'interior', label: 'Inside', hint: 'Only with permission', required: false },
    { value: 'document', label: 'Document', hint: 'A licence or permit on display', required: false },
];

/**
 * Photographs, taken with the device camera and compressed before they leave it.
 *
 * capture="environment" opens the rear camera directly rather than a file
 * browser, which is one tap instead of four and stops an officer picking a
 * screenshot by accident.
 *
 * Uploaded after the record it belongs to, never with it: a 12 MB photograph on
 * a 2G connection must never be able to block a capture from being saved.
 */
export function PhotoCapture({ structureClientUuid, position }: PhotoCaptureProps) {
    const [taken, setTaken] = useState<string[]>([]);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [previews, setPreviews] = useState<Record<string, string>>({});
    const inputs = useRef<Record<string, HTMLInputElement | null>>({});

    const keep = async (kind: string, file: File) => {
        setBusy(kind);
        setError(null);

        try {
            // Compressed and held on the device. It uploads on its own once the
            // building it belongs to has reached the server, which may be hours
            // later and is none of the officer's concern.
            await holdPhotograph(structureClientUuid, kind, file, position);

            setTaken((t) => (t.includes(kind) ? t : [...t, kind]));
        } catch (e) {
            setError(e instanceof Error ? e.message : 'That photograph could not be kept.');
        } finally {
            setBusy(null);
        }
    };

    const required = KINDS.filter((k) => k.required);
    const requiredDone = required.filter((k) => taken.includes(k.value)).length;

    // The mockup's "Required photos" grid: kept tiles in the accent, the rest
    // dashed and waiting. Same storage as before: holdPhotograph keeps the file
    // on the device and it uploads after the record it belongs to.
    return (
        <div className="flex flex-col gap-3">
            <p className="flex items-center justify-between text-ui font-bold text-ink">
                Required photos
                <span className={cx('text-table font-extrabold', requiredDone === required.length ? 'text-green' : 'text-gold-dark')}>
                    {requiredDone} of {required.length}
                </span>
            </p>
            <div className="grid grid-cols-3 gap-2">
                {KINDS.map((kind) => {
                    const done = taken.includes(kind.value);
                    const preview = previews[kind.value];

                    return (
                        <div key={kind.value}>
                            <input
                                ref={(el) => {
                                    inputs.current[kind.value] = el;
                                }}
                                type="file"
                                accept="image/*"
                                capture="environment"
                                className="sr-only"
                                onChange={(e) => {
                                    const file = e.target.files?.[0];

                                    if (file !== undefined) {
                                        setPreviews((p) => ({ ...p, [kind.value]: URL.createObjectURL(file) }));
                                        void keep(kind.value, file);
                                    }

                                    e.target.value = '';
                                }}
                            />
                            <button
                                type="button"
                                aria-label={`${kind.label}: ${done ? 'taken, tap to retake' : kind.hint}`}
                                onClick={() => {
                                    inputs.current[kind.value]?.click();
                                }}
                                className={cx(
                                    'relative flex aspect-[4/3] w-full flex-col justify-end overflow-hidden rounded-sm border p-2 text-left',
                                    done ? 'border-gold/40 bg-gold-soft' : 'border-dashed border-rule-strong bg-raised',
                                )}
                                style={preview === undefined ? undefined : { backgroundImage: `linear-gradient(to top, rgb(0 0 0 / 0.55), transparent 60%), url(${preview})`, backgroundSize: 'cover', backgroundPosition: 'center' }}
                            >
                                <span className={cx('text-table font-extrabold', preview !== undefined ? 'text-white' : 'text-ink')}>{kind.label}</span>
                                <span className={cx('text-[11px] font-semibold', preview !== undefined ? 'text-white/90' : done ? 'text-gold-dark' : 'text-muted')}>
                                    {busy === kind.value
                                        ? 'Keeping…'
                                        : done
                                          ? position === null
                                              ? '✓ kept'
                                              : '✓ geo-tagged'
                                          : kind.required
                                            ? '+ Tap to take'
                                            : 'Optional'}
                                </span>
                            </button>
                        </div>
                    );
                })}
            </div>

            {error !== null && <p className="text-ui text-alert">{error}</p>}
        </div>
    );
}
