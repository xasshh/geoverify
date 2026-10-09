import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { GeoVerifyLockup } from '@/components/GeoVerifyMark';
import { SiteFooter, SiteHeader, type NavItem } from '@/components/PublicSite';

const NAV: NavItem[] = [
    { label: 'Registry', href: '/directory' },
    { label: 'Products', children: [{ label: 'Enumerate', href: '/enumerate', caption: 'Verify any business in Nigeria' }] },
    { label: 'How it works', href: '/#how' },
    { label: 'Become an agent', href: '/become-an-agent' },
];

/** The frame for the privacy policy and the terms: one readable column. */
export function LegalPage({ title, updated, children }: { title: string; updated: string; children: ReactNode }) {
    return (
        <div data-mode="daylight" className="min-h-dvh bg-raised text-ink">
            <Head title={title} />
            <SiteHeader
                brand={<GeoVerifyLockup size={36} />}
                links={NAV}
                signIn={{ label: 'Sign in', href: '/portal/sign-in' }}
                getStarted={{ label: 'Get started', href: '/portal/register' }}
            />
            <main className="mx-auto max-w-[760px] px-4 py-14 sm:px-6">
                <h1 className="font-display text-[2.2rem] leading-[1.1] font-extrabold tracking-[-0.02em]">{title}</h1>
                <p className="mt-2 text-label text-muted">Last updated {updated}</p>
                <div className="legal mt-8 flex flex-col gap-4 text-body text-ink [&_a]:font-bold [&_a]:text-gold-dark [&_a]:underline [&_h2]:mt-6 [&_h2]:font-display [&_h2]:text-[1.35rem] [&_h2]:font-extrabold [&_li]:ml-5 [&_li]:list-disc [&_p]:text-muted [&_ul]:flex [&_ul]:flex-col [&_ul]:gap-1.5 [&_ul]:text-muted">
                    {children}
                </div>
            </main>
            <SiteFooter />
        </div>
    );
}

/** How to reach us: the configured address, or the complaints desk in Enumerate. */
export function Contact({ email }: { email: string | null }) {
    return email !== null ? <a href={`mailto:${email}`}>{email}</a> : <a href="/enumerate/support">Complaints in Enumerate</a>;
}
