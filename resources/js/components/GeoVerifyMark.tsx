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
 * Two inks, one per mode, because one ink cannot serve both. The accent teal
 * is 2.0:1 on the daylight card and the deep teal is 1.2:1 on a dusk surface:
 * either choice alone leaves the mark washed out on half the application. So
 * the artwork ships twice, recoloured from the original gold, and CSS shows the
 * one that belongs to the surface underneath it.
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
 * The wordmark is set in the product's own display face rather than traced from
 * the logo artwork: every heading in this application is Newsreader, and a
 * second typeface used only for the four places the name appears would be a
 * whole extra face to load for one word.
 */
export function GeoVerifyLockup({
    size = 22,
    caption,
    className,
}: {
    size?: number;
    caption?: string;
    className?: string;
}) {
    return (
        <span className={cx('flex items-center gap-2', className)}>
            <GeoVerifyMark size={size} title="GeoVerify" />
            <span className="flex flex-col">
                <span className="font-display text-display-s leading-none text-ink">
                    GeoVerify
                </span>
                {caption !== undefined && (
                    <span className="text-label tracking-[0.12em] text-faint uppercase">
                        {caption}
                    </span>
                )}
            </span>
        </span>
    );
}
