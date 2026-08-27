import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { PortalShell } from '@/components/PortalShell';
import { SelectField, TextField } from '@/components/Field';

/**
 * Who you are, asked once, after the number is proved.
 *
 * Four fields for an individual, five for a company, and nothing that is not
 * needed to issue a code and reach you. The legal name appears only when it
 * applies, because a market trader does not have one and asking implies they
 * should.
 */
export default function Register() {
    const form = useForm({
        person_name: '',
        display_name: '',
        kind: 'individual',
        legal_name: '',
        email: '',
    });

    const isCompany = form.data.kind === 'company';

    return (
        <PortalShell kicker="Nigeria Business Directory" title="Create your account">
            <Head title="Create your account" />

            <p className="mb-8 max-w-[46ch] text-body text-muted">
                Your number is confirmed. Tell us who you are and we will issue your code.
            </p>

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

                <TextField
                    label="Email"
                    name="email"
                    type="email"
                    autoComplete="email"
                    size="field"
                    hint="Optional. For receipts and reports."
                    value={form.data.email}
                    onChange={(event) => {
                        form.setData('email', event.target.value);
                    }}
                    {...(form.errors.email !== undefined && { error: form.errors.email })}
                />

                <Button type="submit" fullWidth disabled={form.processing}>
                    {form.processing ? 'Creating' : 'Create my account'}
                </Button>
            </form>
        </PortalShell>
    );
}
