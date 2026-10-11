/**
 * The guide videos shown on the Help page and, for a newcomer, on Enumerate's
 * home. Built from screenshots of this app (~/geoverify-guides, outside the
 * repository); the steps beside each are the narration, written down, for
 * anybody who would rather read or cannot play sound.
 */
export interface Guide {
    slug: string;
    title: string;
    minutes: string;
    blurb: string;
    steps: string[];
}

export const GUIDES: Guide[] = [
    {
        slug: 'dashboard-tour',
        title: 'A quick tour of your dashboard',
        minutes: '1 minute',
        blurb: 'Where everything is: starting a check, your results, complaints and applying to be an agent.',
        steps: [
            'Home, on the left of the menu, brings you back to your dashboard from anywhere.',
            'New verification is where you start a check on any business, using its CAC number.',
            'My verifications lists every check you have run. Open one to see its progress or download its report.',
            'Complaints is where you tell us if something is not right. We reply within one working day.',
            'Become an agent is where you apply to work with GeoVerify.',
        ],
    },
    {
        slug: 'verify-business',
        title: 'How to verify a business',
        minutes: '2 minutes',
        blurb: 'From the CAC number to a report you can share, step by step.',
        steps: [
            'Press New verification on your dashboard.',
            'Type the business’s CAC number. It is on their certificate, invoice or letterhead, and starts with RC for a company or BN for a business name. Press Search.',
            'Check the name we found on the CAC register is the business you mean.',
            'Choose how deep to check: the official records only, an agent visit with photos, or an agent visit on every trading day. Press Run this check.',
            'Follow the check on its own page. When it is finished, download your report. Anyone can scan its QR code to confirm it is genuine.',
        ],
    },
    {
        slug: 'become-agent',
        title: 'How to become a field agent',
        minutes: '2 minutes',
        blurb: 'The four parts of the application, what to have ready, and what happens after you send it.',
        steps: [
            'Open Become an agent and press Start my application.',
            'About you: your name as on your NIN, where you live, your languages and a clear photo of your face.',
            'Background checks: your NIN (we keep only the last four digits), education, a guarantor and someone to call in an emergency, then photos of your documents.',
            'Your phone: stand outside and run the location test.',
            'Agreements: read each one, tick the box and type your full name to sign. Then press Send my application. We reply by email, usually within a week.',
        ],
    },
];

export const guideVideo = (slug: string): string => `/media/guides/${slug}.mp4`;
export const guidePoster = (slug: string): string => `/media/guides/${slug}.jpg`;
