import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { BusinessAuthLayout } from '@/components/AuthLayouts';
import { Button } from '@/components/Button';
import { cx } from '@/lib/cx';

const LENGTH = 6;

/**
 * Enter the code, as the mobile board draws it: six boxes, a resend that waits
 * its turn, and the one warning worth repeating every time.
 *
 * The boxes are one value underneath. Typing moves forward, backspace moves
 * back, and pasting the whole code from the text message fills every box,
 * because that is what most people will do on the same phone.
 */
export default function VerifyCode({
    masked,
    intent,
    resendAfterSeconds,
}: {
    masked: string | null;
    intent: 'sign-in' | 'reset';
    resendAfterSeconds: number;
}) {
    const status = usePage().props.flash.status;
    const form = useForm({ code: '' });
    const boxes = useRef<(HTMLInputElement | null)[]>([]);
    const [wait, setWait] = useState(resendAfterSeconds);

    useEffect(() => {
        boxes.current[0]?.focus();
    }, []);

    useEffect(() => {
        if (wait <= 0) {
            return;
        }

        const timer = window.setTimeout(() => {
            setWait((w) => w - 1);
        }, 1000);

        return () => {
            window.clearTimeout(timer);
        };
    }, [wait]);

    const digits = form.data.code.padEnd(LENGTH, ' ').slice(0, LENGTH).split('');

    const put = (index: number, value: string) => {
        const clean = value.replace(/\D/g, '');

        if (clean.length > 1) {
            const filled = clean.slice(0, LENGTH);
            form.setData('code', filled);
            boxes.current[Math.min(filled.length, LENGTH - 1)]?.focus();

            return;
        }

        const next = digits.slice();
        next[index] = clean === '' ? ' ' : clean;
        form.setData('code', next.join('').trimEnd());

        if (clean !== '' && index < LENGTH - 1) {
            boxes.current[index + 1]?.focus();
        }
    };

    const submit = () => {
        form.transform((data) => ({ code: data.code.replace(/\s/g, '') }));
        form.post('/portal/verify');
    };

    return (
        <BusinessAuthLayout bare>
            <Head title="Enter your code" />

            <Link
                href="/portal/sign-in"
                aria-label="Back"
                className="flex size-12 items-center justify-center rounded-[14px] border border-rule-strong text-ink hover:bg-sunken"
            >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" aria-hidden="true">
                    <path d="M15 5l-7 7 7 7" />
                </svg>
            </Link>

            <span className="mt-6 flex size-16 items-center justify-center rounded-[18px] bg-gold-soft text-gold">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
                    <path d="M8 2.5h8a1.5 1.5 0 0 1 1.5 1.5v16a1.5 1.5 0 0 1-1.5 1.5H8A1.5 1.5 0 0 1 6.5 20V4A1.5 1.5 0 0 1 8 2.5zM11 18h2" />
                </svg>
            </span>

            <h1 className="mt-6 font-display text-display-l text-ink">Enter the code</h1>
            <p className="mt-1.5 text-body text-muted">
                We sent a 6-digit code to <span className="font-extrabold text-ink">{masked}</span>.
            </p>

            {status !== null && (
                <p role="status" className="mt-4 rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green">
                    {status}
                </p>
            )}

            <form
                className="mt-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
            >
                <fieldset>
                    <legend className="sr-only">Six digit code</legend>
                    <div className="grid grid-cols-6 gap-2.5">
                        {digits.map((d, i) => (
                            <input
                                key={i}
                                ref={(el) => {
                                    boxes.current[i] = el;
                                }}
                                aria-label={i === 0 ? 'Code' : `Digit ${String(i + 1)}`}
                                inputMode="numeric"
                                autoComplete={i === 0 ? 'one-time-code' : 'off'}
                                maxLength={i === 0 ? LENGTH : 1}
                                value={d.trim()}
                                onChange={(e) => {
                                    put(i, e.target.value);
                                }}
                                onKeyDown={(e) => {
                                    if (e.key === 'Backspace' && d.trim() === '' && i > 0) {
                                        boxes.current[i - 1]?.focus();
                                    }
                                }}
                                onPaste={(e) => {
                                    e.preventDefault();
                                    put(i, e.clipboardData.getData('text'));
                                }}
                                className={cx(
                                    'h-16 w-full rounded-[14px] border-2 bg-raised text-center text-display-m font-extrabold text-ink focus:border-gold focus:ring-4 focus:ring-gold-soft focus:outline-none',
                                    form.errors.code === undefined ? 'border-rule-strong' : 'border-alert',
                                )}
                            />
                        ))}
                    </div>
                </fieldset>

                {form.errors.code !== undefined && (
                    <p className="mt-4 rounded-sm bg-alert-soft px-4 py-3 text-ui text-alert-ink">{form.errors.code}</p>
                )}

                <p className="mt-5 text-ui text-muted">
                    Didn’t get it?{' '}
                    {wait > 0 ? (
                        <>
                            Resend in{' '}
                            <span className="numeric-mono font-bold text-ink">
                                {Math.floor(wait / 60)}:{String(wait % 60).padStart(2, '0')}
                            </span>
                        </>
                    ) : (
                        <button
                            type="button"
                            onClick={() => {
                                router.post('/portal/verify/resend', {}, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setWait(resendAfterSeconds);
                                    },
                                });
                            }}
                            className="font-extrabold text-gold hover:text-gold-dark"
                        >
                            Send a new code
                        </button>
                    )}
                </p>

                <p className="mt-10 flex gap-3 rounded-[14px] bg-sunken px-5 py-4 text-ui text-muted">
                    <svg className="mt-0.5 shrink-0 text-gold" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                        <path d="M5.5 10.5h13v10h-13zM8.5 10.5V7a3.5 3.5 0 0 1 7 0v3.5" />
                    </svg>
                    GeoVerify staff will never ask for this code by phone or WhatsApp.
                </p>

                <div className="mt-5">
                    <Button
                        type="submit"
                        variant="primary"
                        size="field-primary"
                        fullWidth
                        busy={form.processing}
                        disabled={form.data.code.replace(/\s/g, '').length !== LENGTH}
                    >
                        {intent === 'reset' ? 'Verify and choose a password' : 'Verify and continue'}
                    </Button>
                </div>
            </form>
        </BusinessAuthLayout>
    );
}
