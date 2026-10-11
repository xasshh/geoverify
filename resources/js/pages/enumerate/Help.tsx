import { Head, Link } from '@inertiajs/react';
import { EnumerateShell } from '@/components/EnumerateShell';
import type { EnumerateFrame } from '@/lib/enumerate';
import { GUIDES, guidePoster, guideVideo } from '@/lib/guides';

/**
 * Help: the guide videos, each with the same steps written down beneath it,
 * and the way to a person when a video does not answer the question.
 */
export default function Help({ frame }: { frame: EnumerateFrame }) {
    return (
        <EnumerateShell current="guide" frame={frame} title="Help & videos">
            <Head title="Help & videos" />
            <p className="max-w-[64ch] text-body text-muted">
                Short videos that show you how Enumerate works, using the real screens. Each one has the same steps written
                underneath, if you would rather read.
            </p>

            <div className="mt-6 flex flex-col gap-5">
                {GUIDES.map((g, i) => (
                    <section key={g.slug} id={g.slug} className="grid gap-5 rounded-card border border-rule bg-raised p-5 lg:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)]">
                        <video
                            controls
                            preload="none"
                            playsInline
                            poster={guidePoster(g.slug)}
                            src={guideVideo(g.slug)}
                            className="aspect-video w-full rounded-sm bg-[#0F1A17]"
                            aria-label={g.title}
                        />
                        <div>
                            <p className="text-label font-extrabold tracking-[0.05em] text-gold uppercase">
                                Video {i + 1} · {g.minutes}
                            </p>
                            <h2 className="mt-1 text-body font-extrabold text-ink">{g.title}</h2>
                            <p className="mt-1 text-ui text-muted">{g.blurb}</p>
                            <ol className="mt-4 flex list-none flex-col gap-2.5 p-0">
                                {g.steps.map((step, n) => (
                                    <li key={step} className="flex gap-3 text-ui text-ink">
                                        <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-gold-soft text-[0.75rem] font-extrabold text-gold-dark">
                                            {n + 1}
                                        </span>
                                        <span>{step}</span>
                                    </li>
                                ))}
                            </ol>
                        </div>
                    </section>
                ))}
            </div>

            <section className="mt-5 flex flex-wrap items-center justify-between gap-4 rounded-card border border-rule bg-sunken px-5 py-4">
                <div>
                    <h2 className="text-ui font-extrabold text-ink">Still stuck?</h2>
                    <p className="text-table text-muted">Tell us what happened and a person on our team will reply within one working day.</p>
                </div>
                <Link href="/enumerate/support" className="rounded-full bg-gold px-5 py-2.5 text-ui font-extrabold text-on-accent hover:bg-gold-dark">
                    Ask our team
                </Link>
            </section>
        </EnumerateShell>
    );
}
