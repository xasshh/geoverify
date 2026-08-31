import { useState } from 'react';

/**
 * The city, moving, behind a sign in form.
 *
 * An aerial run over a Nigerian city with building outlines traced onto it,
 * which is a fair picture of what this system does. It is decoration, so it is
 * treated as decoration: muted, inert to the pointer, and never allowed to be
 * the reason somebody cannot sign in.
 *
 * The poster does the work whenever the video does not. It is 42 KB against the
 * video's 589 KB, it paints on the first frame, and it is what somebody sees on
 * a connection where the video would be an imposition rather than a flourish.
 * Three cases fall back to it: a browser that refuses to autoplay, a person who
 * has asked for reduced motion, and a handset with Save-Data on. That last one
 * matters here more than it usually would, because this is the screen a field
 * officer opens on mobile data in Abuja and a login page is not worth half a
 * megabyte of somebody's bundle.
 *
 * Neither file is precached. The service worker's glob covers public/build and
 * these sit in public/brand, so the offline install stays the size it was. The
 * mark is the opposite case and goes through the bundle for the same reason
 * inverted: it has to be there with no signal.
 */
export function CityBackdrop() {
    // Decided once, before the first paint, so the video element is never
    // mounted for somebody who asked not to have it. Read lazily rather than in
    // an effect: this app renders only in the browser, and setting state from an
    // effect would mount the video and then throw it away.
    const [stilled] = useState<boolean>(() => {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return true;
        }

        // Non standard and absent on most browsers, so it is read defensively
        // rather than typed.
        const connection = (navigator as Navigator & { connection?: { saveData?: boolean } })
            .connection;

        return connection?.saveData === true;
    });

    return (
        <div className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
            {stilled ? (
                <img src="/brand/skyline.jpg" alt="" className="h-full w-full object-cover" />
            ) : (
                <video
                    className="h-full w-full object-cover"
                    poster="/brand/skyline.jpg"
                    src="/brand/skyline.mp4"
                    autoPlay
                    loop
                    muted
                    playsInline
                    preload="metadata"
                />
            )}

            {/*
                A scrim, not a tint. The form sits over the busiest part of the
                frame and the type has to hold its contrast wherever the loop
                happens to be, so this is heavier behind the card and lighter at
                the edges rather than a flat wash over everything.
            */}
            <div className="absolute inset-0 bg-gradient-to-b from-[#0E1E2E]/70 via-[#0E1E2E]/55 to-[#0E1E2E]/80" />
        </div>
    );
}
