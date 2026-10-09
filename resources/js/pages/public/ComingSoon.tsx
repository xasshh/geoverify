import { Head, Link } from '@inertiajs/react';
import { GeoVerifyLockup } from '@/components/GeoVerifyMark';

const WHAT: Record<string, { title: string; body: string }> = {
    portal: {
        title: 'The Business Portal is coming soon',
        body: 'Claiming your listing, selling from your storefront and managing your verification will open here shortly.',
    },
    invest: {
        title: 'The Investor Portal is coming soon',
        body: 'Verified businesses and the opportunities they publish, behind a KYC check, will open here shortly.',
    },
};

/** A surface that is built but not yet open to the public. */
export default function ComingSoon({ surface }: { surface: string }) {
    const what = WHAT[surface] ?? { title: 'Coming soon', body: 'This part of GeoVerify will open shortly.' };

    return (
        <div data-mode="daylight" className="flex min-h-dvh flex-col bg-raised text-ink">
            <Head title={what.title} />
            <header className="border-b border-rule px-4 py-4 sm:px-6">
                <a href="/" aria-label="GeoVerify home" className="inline-block">
                    <GeoVerifyLockup size={36} />
                </a>
            </header>
            <main className="mx-auto flex w-full max-w-[640px] flex-1 flex-col items-start justify-center px-4 py-16 sm:px-6">
                <span className="rounded-full bg-amber-soft px-3 py-1 text-label font-extrabold text-amber-ink">Coming soon</span>
                <h1 className="mt-4 font-display text-[2.2rem] leading-[1.08] font-extrabold tracking-[-0.02em] sm:text-[2.8rem]">{what.title}</h1>
                <p className="mt-4 max-w-[52ch] text-body text-muted">{what.body}</p>
                <p className="mt-2 max-w-[52ch] text-body text-muted">
                    Meanwhile, you can verify any Nigerian business on Enumerate, free while it launches.
                </p>
                <div className="mt-8 flex flex-wrap gap-3">
                    <Link href="/enumerate" className="rounded-full bg-gold-dark px-5 py-2.5 text-ui font-extrabold text-on-accent hover:bg-gold">
                        Go to Enumerate
                    </Link>
                    <a href="/" className="rounded-full border border-rule-strong px-5 py-2.5 text-ui font-extrabold text-ink hover:bg-sunken">
                        Back to the home page
                    </a>
                </div>
            </main>
        </div>
    );
}
