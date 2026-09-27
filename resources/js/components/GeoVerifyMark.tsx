import markDaylightUrl from '../../images/geoverify-mark.png';
import markDuskUrl from '../../images/geoverify-mark-dusk.png';
import { cx } from '@/lib/cx';

/**
 * The GeoVerify mark: the supplied artwork, not a redrawing of it.
 *
 * This was an inline SVG traced by eye, which was close but never exactly the
 * logo. The artwork itself is two flat inks and an alpha channel, so it costs
 * 17 KB quantised and is pixel-for-pixel correct at every size the interface
 * asks for. Correctness beat the theoretical advantages of a vector here.
 *
 * Imported through Vite rather than dropped in public/, deliberately. The
 * service worker precaches public/build, so going through the bundle is what
 * keeps the mark on screen for an officer signing in with no signal. The login
 * backdrop video is the opposite case and lives in public/ for the same reason
 * inverted: it must never be precached.
 *
 * Two files, one per mode. Since the UI/UX guide of September 2026 both carry
 * the logo teal (#4DB8B0), which the guide sets on white as well as on dark
 * ground; the deep teal daylight ink it replaced read as a different mark. The
 * pair is kept so a mode can take its own ink again without touching callers.
 *
 * Both are rendered rather than switched in JavaScript. The mode is a data
 * attribute on a wrapper somewhere above this, not state this component can
 * read, and a mark that flickered on hydration would be worse than 16 KB.
 *
 * The white check is untouched in both. A two ink logo with one ink recoloured
 * is still the same mark.
 */
export function GeoVerifyMark({
    size = 24,
    className,
    title,
    ink = 'auto',
}: {
    size?: number;
    className?: string;
    title?: string;
    /**
     * Which ink to use. 'auto' follows data-mode, which is right whenever the
     * mark sits on the surface of its own mode. A dark band inside a daylight
     * page is the case it gets wrong: the mode says daylight, the ground under
     * the mark is not, and the deep teal artwork disappears into it. Those
     * callers say 'light' and mean it.
     */
    ink?: 'auto' | 'light' | 'dark';
}) {
    const shared = {
        width: size,
        height: size,
        alt: title ?? '',
        // Decorative in the shells, where the name sits beside it in text.
        // Given a title it becomes a labelled image instead.
        'aria-hidden': title === undefined ? true : undefined,
        draggable: false as const,
    };

    if (ink !== 'auto') {
        return (
            <img
                src={ink === 'light' ? markDuskUrl : markDaylightUrl}
                {...shared}
                className={cx('shrink-0 object-contain', className)}
            />
        );
    }

    return (
        <>
            <img
                src={markDaylightUrl}
                {...shared}
                className={cx('gv-mark-daylight shrink-0 object-contain', className)}
            />
            <img
                src={markDuskUrl}
                {...shared}
                // Hidden from assistive technology in both cases: the two
                // images are one mark, and announcing it twice is noise.
                alt=""
                aria-hidden
                className={cx('gv-mark-dusk shrink-0 object-contain', className)}
            />
        </>
    );
}

/**
 * The mark with the name beside it.
 *
 * The wordmark is Montserrat 700 in the logo teal, as the guide specifies, and
 * Montserrat is used for nothing else. The caption beneath says which surface
 * this is: Merchant hub, Supervisor console, and so on.
 */
export function GeoVerifyLockup({
    size = 40,
    caption,
    className,
    tone = 'auto',
}: {
    size?: number;
    caption?: string;
    className?: string;
    /** 'light' when the lockup sits on a dark band inside a daylight page. */
    tone?: 'auto' | 'light';
}) {
    return (
        <span className={cx('flex items-center gap-2.5', className)}>
            <GeoVerifyMark size={size} title="GeoVerify" ink={tone === 'light' ? 'light' : 'auto'} />
            <span className="flex flex-col">
                <span className="font-wordmark text-[1.375rem] leading-none font-bold tracking-[-0.01em] text-logo">
                    GeoVerify
                </span>
                {caption !== undefined && (
                    <span
                        className={cx(
                            'mt-1 text-[0.6875rem] leading-none font-extrabold tracking-[0.05em] uppercase',
                            tone === 'light' ? 'text-inverse/70' : 'text-muted',
                        )}
                    >
                        {caption}
                    </span>
                )}
            </span>
        </span>
    );
}
