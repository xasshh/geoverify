import { useRef, useState } from 'react';
import { Button } from '@/components/Button';
import { StatusPill } from '@/components/StatusPill';
import { holdPhotograph } from '@/lib/offline/queue';
import { cx } from '@/lib/cx';

interface PhotoCaptureProps {
    /** The building's own client uuid, which exists before any server has seen it. */
    structureClientUuid: string;
    position: { longitude: number; latitude: number } | null;
}

const KINDS: Array<{ value: string; label: string; hint: string }> = [
    { value: 'facade', label: 'Front', hint: 'The whole building from the street' },
    { value: 'signage', label: 'Sign', hint: 'Close enough to read the name' },
    { value: 'street_context', label: 'Street', hint: 'What is either side of it' },
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

    return (
        <div className="flex flex-col gap-3">
            <div className="grid grid-cols-3 gap-2">
                {KINDS.map((kind) => {
                    const done = taken.includes(kind.value);

                    return (
                        <div key={kind.value} className="flex flex-col gap-1.5">
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
                                        void keep(kind.value, file);
                                    }

                                    e.target.value = '';
                                }}
                            />
                            <Button
                                variant={done ? 'primary' : 'secondary'}
                                size="field"
                                fullWidth
                                busy={busy === kind.value}
                                onClick={() => {
                                    inputs.current[kind.value]?.click();
                                }}
                            >
                                {kind.label}
                            </Button>
                            <span className={cx('text-center text-label', 'text-faint')}>
                                {done ? 'Kept' : kind.hint}
                            </span>
                        </div>
                    );
                })}
            </div>

            {error !== null && <p className="text-ui text-alert">{error}</p>}

            {taken.length > 0 && (
                <div className="flex items-center gap-2">
                    <StatusPill
                        tone="accepted"
                        label={`${String(taken.length)} of ${String(KINDS.length)} kept`}
                        size="sm"
                    />
                </div>
            )}
        </div>
    );
}
