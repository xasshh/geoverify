import { useRef, useState } from 'react';
import { Button } from '@/components/Button';
import { StatusPill } from '@/components/StatusPill';
import { savePhotograph, type PhotographResult } from '@/lib/capture';
import { cx } from '@/lib/cx';

interface PhotoCaptureProps {
    structureId: number;
    position: { longitude: number; latitude: number } | null;
    fieldSessionId?: number;
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
export function PhotoCapture({ structureId, position, fieldSessionId }: PhotoCaptureProps) {
    const [taken, setTaken] = useState<PhotographResult[]>([]);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const inputs = useRef<Record<string, HTMLInputElement | null>>({});

    const upload = async (kind: string, file: File) => {
        setBusy(kind);
        setError(null);

        try {
            const result = await savePhotograph(file, {
                structure_id: structureId,
                kind,
                ...(position === null
                    ? {}
                    : { device_longitude: position.longitude, device_latitude: position.latitude }),
                ...(fieldSessionId === undefined ? {} : { field_session_id: fieldSessionId }),
            });

            setTaken((t) => [...t.filter((p) => p.kind !== kind), result]);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'That photograph did not send.');
        } finally {
            setBusy(null);
        }
    };

    return (
        <div className="flex flex-col gap-3">
            <div className="grid grid-cols-3 gap-2">
                {KINDS.map((kind) => {
                    const done = taken.find((p) => p.kind === kind.value);
                    const far = done?.distance_from_subject_m != null && done.distance_from_subject_m > 150;

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
                                        void upload(kind.value, file);
                                    }

                                    e.target.value = '';
                                }}
                            />
                            <Button
                                variant={done === undefined ? 'secondary' : 'primary'}
                                size="field"
                                fullWidth
                                busy={busy === kind.value}
                                onClick={() => {
                                    inputs.current[kind.value]?.click();
                                }}
                            >
                                {kind.label}
                            </Button>
                            <span
                                className={cx(
                                    'text-center text-label',
                                    far ? 'text-amber' : 'text-faint',
                                )}
                            >
                                {done === undefined
                                    ? kind.hint
                                    : far
                                      ? `${String(Math.round(done.distance_from_subject_m ?? 0))} m away`
                                      : 'Taken'}
                            </span>
                        </div>
                    );
                })}
            </div>

            {taken.some((p) => p.from_device_camera === false) && (
                <p className="border-l-2 border-amber pl-3 text-ui text-muted">
                    One of these has no camera information in it. That is what a screenshot or a
                    saved image looks like, and a supervisor will be asked to check it.
                </p>
            )}

            {error !== null && <p className="text-ui text-alert">{error}</p>}

            {taken.length > 0 && (
                <div className="flex items-center gap-2">
                    <StatusPill
                        tone="accepted"
                        label={`${String(taken.length)} of ${String(KINDS.length)} taken`}
                        size="sm"
                    />
                </div>
            )}
        </div>
    );
}
