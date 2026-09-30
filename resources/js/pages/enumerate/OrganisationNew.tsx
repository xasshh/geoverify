import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { EnumerateShell } from '@/components/EnumerateShell';
import type { EnumerateFrame } from '@/lib/enumerate';

const INPUT = 'h-11 rounded-sm border border-rule-strong bg-raised px-3.5 text-ui font-normal text-ink focus:border-gold focus:outline-none';

/**
 * Opening an organisation account. The person opening it takes its first
 * admin seat; the organisation can fund its wallet and run checks at once, and
 * bulk verification and projects open when we approve it.
 */
export default function OrganisationNew({ frame }: { frame: EnumerateFrame }) {
    const form = useForm({ name: '', rc_number: '', email: '' });

    return (
        <EnumerateShell current={null} frame={frame} title="Open an organisation account">
            <Head title="Open an organisation account" />

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px] lg:items-start">
                <form
                    className="flex flex-col gap-4 rounded-card border border-rule bg-raised px-6 py-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/enumerate/organisations');
                    }}
                >
                    <label className="flex flex-col gap-1.5 text-table font-bold text-ink">
                        Registered name
                        <input className={INPUT} value={form.data.name} maxLength={160} onChange={(e) => { form.setData('name', e.target.value); }} placeholder="Acme Logistics Ltd" />
                    </label>
                    <label className="flex flex-col gap-1.5 text-table font-bold text-ink">
                        RC number <span className="font-normal text-muted">(helps us approve you quickly)</span>
                        <input className={INPUT} value={form.data.rc_number} maxLength={32} onChange={(e) => { form.setData('rc_number', e.target.value); }} placeholder="RC 1234567" />
                    </label>
                    <label className="flex flex-col gap-1.5 text-table font-bold text-ink">
                        Organisation email <span className="font-normal text-muted">(for invoices)</span>
                        <input className={INPUT} type="email" value={form.data.email} maxLength={180} onChange={(e) => { form.setData('email', e.target.value); }} placeholder="accounts@acme.ng" />
                    </label>
                    {form.errors.name !== undefined && <p role="alert" className="text-ui font-semibold text-alert-ink">{form.errors.name}</p>}
                    <div>
                        <Button type="submit" variant="primary" size="field" busy={form.processing} disabled={form.data.name.trim().length < 3}>
                            Open the account
                        </Button>
                    </div>
                </form>

                <aside className="rounded-card bg-ink px-5 py-5 text-inverse">
                    <p className="text-body font-extrabold">What an organisation gets</p>
                    <ul className="mt-3 flex flex-col gap-2 text-table text-inverse/80">
                        <li>• Its own wallet, funded by card or bank transfer</li>
                        <li>• Team seats: admin, project lead, requester, viewer</li>
                        <li>• Bulk verification from a CSV, Tier 3 included</li>
                        <li>• Custom enumeration projects, scoped with an account manager</li>
                    </ul>
                    <p className="mt-4 text-table text-inverse/60">You become its first admin. Your own checks stay on your own account.</p>
                </aside>
            </div>
        </EnumerateShell>
    );
}
