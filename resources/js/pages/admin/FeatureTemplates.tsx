import { Head, usePage } from '@inertiajs/react';
import { ConsoleShell } from '@/components/ConsoleShell';
import { FeatureClassList, type ClassVocabulary } from '@/components/FeatureClassEditor';
import type { FeatureClassRow } from '@/lib/campaign';

interface Props {
    templates: FeatureClassRow[];
    vocabulary: ClassVocabulary;
}

/**
 * The global feature class templates.
 *
 * A campaign copies these when area capture is switched on and then owns its
 * copy, so a change made here reaches the next campaign that copies it and
 * never one already in the field.
 */
export default function FeatureTemplates({ templates, vocabulary }: Props) {
    const flash = usePage().props.flash.status;

    return (
        <ConsoleShell current="featureTemplates">
            <Head title="Feature classes" />

            <div className="mx-auto max-w-[1200px] px-6 pb-24">
                <header className="mt-8 border-b border-rule pb-3">
                    <p className="text-label font-semibold tracking-[0.05em] text-gold uppercase">In house</p>
                    <h1 className="font-display text-display-m text-ink">Feature classes</h1>
                    <p className="mt-1 max-w-[70ch] text-ui text-muted">
                        The templates a campaign copies when it captures land, water and the things on it. Editing a
                        template changes the next campaign that copies it, never one already running. Changing the
                        questions saves a new version; features captured earlier keep the version they were asked.
                    </p>
                </header>

                {flash !== null && (
                    <p role="status" className="mt-5 rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green">
                        {flash}
                    </p>
                )}

                <div className="mt-6">
                    <FeatureClassList classes={templates} vocabulary={vocabulary} postUrl="/admin/feature-templates" />
                </div>
            </div>
        </ConsoleShell>
    );
}
