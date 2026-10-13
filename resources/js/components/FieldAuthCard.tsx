import type { ReactNode } from 'react';
import { CityBackdrop } from '@/components/CityBackdrop';
import { GeoVerifyMark } from '@/components/GeoVerifyMark';

/** The field portal's sign-in card on the city backdrop, for the pages around /agent. */
export function FieldAuthCard({ title, intro, children, below }: { title: string; intro: ReactNode; children: ReactNode; below?: ReactNode }) {
    return (
        <div data-mode="daylight" className="relative flex min-h-dvh items-center justify-center px-4 py-8 sm:px-6 sm:py-12">
            <CityBackdrop />
            <div className="relative w-full max-w-sm">
                <div className="rounded-card bg-raised shadow-[0_18px_48px_rgb(15_26_23/0.34)]">
                    <div className="flex flex-col items-center px-6 pt-7 pb-5 text-center">
                        <a href="/" aria-label="GeoVerify home">
                            <GeoVerifyMark size={56} className="mb-3" title="GeoVerify" />
                        </a>
                        <h1 className="font-display text-[1.5rem] leading-tight font-extrabold text-ink">{title}</h1>
                        <p className="mt-2 text-ui text-muted">{intro}</p>
                    </div>
                    <div className="mx-6 border-t border-rule" />
                    <div className="px-6 py-6">{children}</div>
                </div>
                {below !== undefined && <p className="mt-4 text-center text-ui text-white/90">{below}</p>}
            </div>
        </div>
    );
}
