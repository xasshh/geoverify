import { Contact, LegalPage } from '@/components/LegalPage';

/** What GeoVerify collects, why, who else sees it, and what people can ask for. */
export default function Privacy({ contactEmail }: { contactEmail: string | null }) {
    return (
        <LegalPage title="Privacy policy" updated="9 October 2026">
            <p>
                GeoVerify (logyon.com) keeps a register of Nigerian businesses confirmed on the ground, and runs Enumerate,
                which checks a business against the official registers and, where asked, with an officer visit. This policy
                says what personal data we hold, why, who else handles it, and what you can ask us to do. We process
                personal data in line with the Nigeria Data Protection Act 2023.
            </p>

            <h2>What we collect</h2>
            <ul>
                <li>
                    <strong>Your account:</strong> your name, your email address, a scrambled (hashed) form of your password,
                    and an optional phone number. If you continue with Google, we receive your name, email address and a
                    Google account identifier, and nothing else from your Google account.
                </li>
                <li>
                    <strong>Your Enumerate checks:</strong> the CAC (RC or BN) numbers you look up and what the registers
                    return for them: the business name, status, registration date, address and its directors&apos; names and
                    roles. We do not keep directors&apos; phone numbers, emails or home addresses even when a register returns
                    them.
                </li>
                <li>
                    <strong>Businesses on the register:</strong> what our field officers record when they visit, such as the
                    trading name, sector, location and photographs. Photographs of the inside of premises are never
                    published. A business that has not claimed its listing appears, if at all, only by name, sector, ward and
                    local government, and only if it displays its name on the street.
                </li>
                <li>
                    <strong>Identity numbers:</strong> where a national identification number (NIN) is checked, we never
                    store the number itself, only a scrambled form, the last four digits and the result of the check.
                </li>
                <li>
                    <strong>Technical data:</strong> a session cookie that keeps you signed in, and the IP address of
                    requests, which we use to limit abuse such as repeated sign in attempts. We do not use advertising or
                    tracking cookies.
                </li>
            </ul>

            <h2>Why we use it</h2>
            <ul>
                <li>To run your account and the checks you ask for (performing our service to you).</li>
                <li>To keep an accurate, auditable register of businesses (our legitimate interest, and the interest of those relying on it).</li>
                <li>To send account emails: confirming your address, resetting a password, invitations. We do not send marketing email.</li>
                <li>To prevent fraud and abuse, and to meet legal obligations.</li>
            </ul>

            <h2>Who else handles it</h2>
            <p>We share data only with the services that make GeoVerify work, under their own data protection terms:</p>
            <ul>
                <li><strong>Prembly</strong>, which looks up CAC and FIRS records for Enumerate checks.</li>
                <li><strong>Resend</strong>, which delivers our account emails.</li>
                <li><strong>Google</strong>, if you choose to continue with Google.</li>
                <li><strong>Paystack</strong>, if you pay us, which handles card and bank payments. We never see or store your card number.</li>
                <li><strong>Hostinger</strong>, which hosts our servers.</li>
            </ul>
            <p>We do not sell personal data. We disclose it to authorities only where the law requires.</p>

            <h2>How long we keep it</h2>
            <p>
                Because GeoVerify is a register and a record of verification, we do not erase records outright: a removed
                item is withdrawn from view and kept, with a note of who removed it and why, so that what was verified
                can still be accounted for. Account data is kept while your account is open and for as long afterwards as
                the law and resolving disputes require.
            </p>

            <h2>Your rights</h2>
            <ul>
                <li>See the personal data we hold about you, and have it corrected.</li>
                <li>Withdraw consent you have given, and object to processing based on our legitimate interest.</li>
                <li>
                    Ask for your business to be removed from the public directory. Every listing offers this, and you do not
                    need to claim the business first.
                </li>
                <li>Ask us to restrict or remove your data, which we honour by withdrawing it from use as described above.</li>
                <li>Complain to the Nigeria Data Protection Commission if you are not satisfied with our answer.</li>
            </ul>
            <p>
                To use any of these, contact us at <Contact email={contactEmail} />.
            </p>

            <h2>Security</h2>
            <p>
                Data travels encrypted (HTTPS), passwords and identity numbers are stored only in scrambled form, and staff
                access is limited by role and recorded.
            </p>

            <h2>Changes</h2>
            <p>We will update this page when what we do changes, and change the date at the top.</p>
        </LegalPage>
    );
}
