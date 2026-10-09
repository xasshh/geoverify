import { Head, router, useForm, usePage } from '@inertiajs/react';
import { BusinessAuthLayout } from '@/components/AuthLayouts';
import { Button } from '@/components/Button';

/**
 * "Check your inbox": a signed in account whose email is not yet proved
 * lands here, and stays here, until the link in the email is followed.
 */
export default function VerifyEmail({ email }: { email: string | null }) {
    const status = usePage().props.flash.status;
    const resend = useForm<{ email?: string }>({});

    return (
        <BusinessAuthLayout mobileTitle="Check your email" mobileSubtitle={email ?? ''}>
            <Head title="Verify your email" />
            <div className="flex size-14 items-center justify-center rounded-full bg-gold-soft text-gold-dark">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                    <path d="M3 6.5h18v11H3zM3.5 7l8.5 6.5L20.5 7" />
                </svg>
            </div>
            <h1 className="mt-5 font-display text-display-l text-ink">Verify your email</h1>
            <p className="mt-2 max-w-[46ch] text-body text-muted">
                We sent a link to <strong className="text-ink">{email}</strong>. Open it to finish setting up your
                account. It works for 24 hours, on this device or any other.
            </p>

            {status !== null && (
                <p role="status" className="mt-5 rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green">
                    {status}
                </p>
            )}
            {resend.errors.email !== undefined && (
                <p className="mt-5 rounded-sm bg-alert-soft px-4 py-3 text-ui text-alert-ink">{resend.errors.email}</p>
            )}

            <div className="mt-6 flex flex-col gap-3">
                <Button
                    variant="primary"
                    size="field-primary"
                    fullWidth
                    busy={resend.processing}
                    onClick={() => {
                        resend.post('/portal/email/resend', { preserveScroll: true });
                    }}
                >
                    Send it again
                </Button>
                <Button
                    variant="secondary"
                    size="field-primary"
                    fullWidth
                    onClick={() => {
                        router.post('/portal/sign-out');
                    }}
                >
                    Use a different email
                </Button>
            </div>
            <p className="mt-6 text-ui text-muted">Nothing arrived? Look in your spam or promotions folder.</p>
        </BusinessAuthLayout>
    );
}
