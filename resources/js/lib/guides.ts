/**
 * The guide videos: Enumerate's on its Help page and, for a newcomer, on its
 * home; the agent's on their application in the field portal. Built from screenshots of this app (~/geoverify-guides, outside the
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

export const ENUMERATE_GUIDES: Guide[] = [
    {
        slug: 'dashboard-tour',
        title: 'A quick tour of your dashboard',
        minutes: '1 minute',
        blurb: 'Where everything is: starting a check, your results and complaints.',
        steps: [
            'Home, on the left of the menu, brings you back to your dashboard from anywhere.',
            'New verification is where you start a check on any business, using its CAC number.',
            'My verifications lists every check you have run. Open one to see its progress or download its report.',
            'Complaints is where you tell us if something is not right. We reply within one working day.',
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
];

/** The agent's onboarding, shown on their application in the field portal. */
export const AGENT_GUIDE: Guide = {
    slug: 'become-agent',
    title: 'How to become a field agent',
    minutes: '2 minutes',
    blurb: 'Signing up, the four parts of the application, and what happens after you send it.',
    steps: [
        'Press Sign up as an agent on the GeoVerify home page, and open your account with your name, email and a password.',
        'Confirm your email with the link we send you. Your application opens in the field portal.',
        'About you: your name as on your NIN, where you live, your languages and a clear photo of your face.',
        'Background checks: your NIN (we keep only the last four digits), education, a guarantor and someone to call in an emergency, then photos of your documents.',
        'Your phone: stand outside and run the location test. Then read and sign the agreements, and press Send my application.',
        'We reply by email, usually within a week. Once approved, sign in at logyon.com/agent with the same email and password and your work unlocks.',
    ],
};

export const guideVideo = (slug: string): string => `/media/guides/${slug}.mp4`;
export const guidePoster = (slug: string): string => `/media/guides/${slug}.jpg`;
