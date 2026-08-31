import markUrl from '../../images/geoverify-mark.png';
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
 * Not tinted. The gold and the white check are the mark, and both read on the
 * daylight and dusk surfaces this system uses, so there is nothing to recolour.
 */
export function GeoVerifyMark({
    size = 24,
    className,
    title,
}: {
    size?: number;
    className?: string;
    title?: string;
}) {
    return (
        <img
            src={markUrl}
            width={size}
            height={size}
            alt={title ?? ''}
            aria-hidden={title === undefined ? true : undefined}
            // Decorative in the shells, where the name sits beside it in text.
            // Given a title it becomes a labelled image instead.
            className={cx('shrink-0 object-contain', className)}
            draggable={false}
        />
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
