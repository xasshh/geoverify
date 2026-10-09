import { Head, Link, useForm } from '@inertiajs/react';
import { GoogleButton } from '@/components/GoogleButton';
import { Button } from '@/components/Button';
import { AudienceTabs, BusinessAuthLayout } from '@/components/AuthLayouts';
import { SelectField, TextField } from '@/components/Field';

/**
 * Who you are, asked once. By email (the only way since SMS was switched
 * off): name, email and a password, then a link to verify the email. With SMS
 * on, the number is already proved when this is shown.
 *
 * Four fields for an individual, five for a company, and nothing that is not
 * needed to issue a code and reach you. The legal name appears only when it
 * applies, because a market trader does not have one and asking implies they
 * should.
 */
export default function Register({
    audience: initial,
    byEmail = false,
    email: presetEmail = '',
    portalOpen = true,
}: {
    audience: 'buyer' | 'business';
    byEmail?: boolean;
    email?: string;
    portalOpen?: boolean;
}) {
    const form = useForm({
        audience: initial,
        password: '',
        password_confirmation: '',
        phone: '',
        person_name: '',
        display_name: '',
        kind: 'individual',
        legal_name: '',
        email: presetEmail,
    });
    const subtitle = byEmail ? 'We will email you a link to confirm your address.' : 'Your number is confirmed.';

    const isCompany = form.data.kind === 'company';
    const buying = form.data.audience === 'buyer';

    return (
        <BusinessAuthLayout mobileTitle="Create your account" mobileSubtitle={subtitle}>
            <Head title="Create your account" />

            <h1 className="hidden font-display text-display-l text-ink lg:block">Create your account</h1>
            <p className="mt-1.5 mb-6 max-w-[46ch] text-body text-muted">
                {byEmail
                    ? buying
                        ? portalOpen
                            ? 'Your name, your email and a password. We send a link to confirm the email.'
                            : 'Create your Enumerate account: your name, your email and a password. We send a link to confirm the email.'
                        : 'Tell us who you are and we will issue your business ID once your email is confirmed.'
                    : buying
                      ? 'Your number is confirmed. Tell us your name and you are in.'
                      : 'Your number is confirmed. Tell us who you are and we will issue your business ID.'}
            </p>

            <div className={portalOpen ? 'mb-6' : 'hidden'}>
                <AudienceTabs
                    value={form.data.audience}
                    onChange={(value) => {
                        form.setData('audience', value);
                    }}
                />
            </div>

            {byEmail && buying && (
                <div className="mb-6 flex flex-col gap-4">
                    <GoogleButton next={portalOpen ? 'portal' : 'enumerate'} label="Sign up with Google" />
                </div>
            )}

            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/portal/register');
                }}
                className="flex flex-col gap-6"
            >
                <TextField
                    label="Your name"
                    name="person_name"
                    autoComplete="name"
                    size="field"
                    value={form.data.person_name}
                    onChange={(event) => {
                        form.setData('person_name', event.target.value);
                    }}
                    {...(form.errors.person_name !== undefined && { error: form.errors.person_name })}
                />

                {!buying && (
                    <>
                <SelectField
                    label="Are you registering as"
                    name="kind"
                    size="field"
                    value={form.data.kind}
                    onChange={(event) => {
                        form.setData('kind', event.target.value);
                    }}
                    {...(form.errors.kind !== undefined && { error: form.errors.kind })}
                >
                    <option value="individual">An individual</option>
                    <option value="company">A company</option>
                </SelectField>

                <TextField
                    label={isCompany ? 'Company name' : 'Business name'}
                    name="display_name"
                    size="field"
                    hint="What people call it. This is what appears on your listing."
                    value={form.data.display_name}
                    onChange={(event) => {
                        form.setData('display_name', event.target.value);
                    }}
                    {...(form.errors.display_name !== undefined && {
                        error: form.errors.display_name,
                    })}
                />

                {isCompany && (
                    <TextField
                        label="Registered name"
                        name="legal_name"
                        size="field"
                        hint="As written on your CAC certificate, if you have one."
                        value={form.data.legal_name}
                        onChange={(event) => {
                            form.setData('legal_name', event.target.value);
                        }}
                        {...(form.errors.legal_name !== undefined && {
                            error: form.errors.legal_name,
                        })}
                    />
                )}

                    </>
                )}

                <TextField
                    label="Email"
                    name="email"
                    type="email"
                    autoComplete="email"
                    size="field"
                    hint={byEmail ? 'You sign in with this. We send a link to confirm it.' : 'Optional. For receipts and reports.'}
                    value={form.data.email}
                    onChange={(event) => {
                        form.setData('email', event.target.value);
                    }}
                    {...(form.errors.email !== undefined && { error: form.errors.email })}
                />

                {byEmail && (
                    <>
                        <TextField
                            label="Password"
                            name="password"
                            type="password"
                            autoComplete="new-password"
                            size="field"
                            hint="At least ten characters."
                            value={form.data.password}
                            onChange={(event) => {
                                form.setData('password', event.target.value);
                            }}
                            {...(form.errors.password !== undefined && { error: form.errors.password })}
                        />
                        <TextField
                            label="Password again"
                            name="password_confirmation"
                            type="password"
                            autoComplete="new-password"
                            size="field"
                            value={form.data.password_confirmation}
                            onChange={(event) => {
                                form.setData('password_confirmation', event.target.value);
                            }}
                        />
                        {!buying && (
                            <TextField
                                label="Business phone"
                                name="phone"
                                type="tel"
                                autoComplete="tel"
                                size="field"
                                hint="Optional. So buyers and our team can call you."
                                value={form.data.phone}
                                onChange={(event) => {
                                    form.setData('phone', event.target.value);
                                }}
                                {...(form.errors.phone !== undefined && { error: form.errors.phone })}
                            />
                        )}
                    </>
                )}

                <Button type="submit" variant="primary" size="field-primary" fullWidth busy={form.processing}>
                    Create my account
                </Button>
            </form>

            {byEmail && (
                <p className="mt-6 text-center text-ui text-muted">
                    Already have an account?{' '}
                    <Link href={portalOpen ? '/portal/sign-in' : '/enumerate/sign-in'} className="font-extrabold text-gold hover:text-gold-dark">
                        Sign in
                    </Link>
                </p>
            )}
        </BusinessAuthLayout>
    );
}
