import { Contact, LegalPage } from '@/components/LegalPage';

/** The terms of using GeoVerify and Enumerate. */
export default function Terms({ contactEmail }: { contactEmail: string | null }) {
    return (
        <LegalPage title="Terms of service" updated="9 October 2026">
            <p>
                These terms apply when you use logyon.com, including the GeoVerify business directory and Enumerate. By
                creating an account or running a check, you agree to them.
            </p>

            <h2>The service</h2>
            <p>
                Enumerate checks a Nigerian business against the Corporate Affairs Commission and tax registers and, at the
                deeper tiers, sends a trained officer to the address. While Enumerate launches, checks are free. If we
                introduce prices, they will be shown before you run a check, and you will never be charged for a check
                without being told the price first.
            </p>

            <h2>Your account</h2>
            <ul>
                <li>Give your real name and an email address you control, and keep your password to yourself.</li>
                <li>You are responsible for what is done through your account.</li>
                <li>One person, one account. Organisation seats are for named people, not shared logins.</li>
            </ul>

            <h2>Using results fairly</h2>
            <ul>
                <li>
                    A check reports what the registers held and what our officer found on the day. It is evidence to help
                    you decide, not a guarantee of a business&apos;s conduct, solvency or future, and not legal advice.
                </li>
                <li>
                    Use results for legitimate purposes such as deciding whether to pay, partner with or hire a business. Do
                    not use them to harass anybody, or resell them as your own data service.
                </li>
                <li>Do not try to break, overload, scrape or get around the limits of the service.</li>
            </ul>

            <h2>Businesses on the register</h2>
            <p>
                Businesses appear because our officers recorded them. A business can ask to be removed from the public
                directory from its listing page without creating an account.
            </p>

            <h2>Our responsibility</h2>
            <p>
                We work to keep the register accurate and the service available, but registers can be wrong or out of
                date, and the service may sometimes be unavailable. To the extent the law allows, we are not liable for
                losses arising from decisions you make using a result, and our total liability to you is limited to what
                you paid us for the check concerned.
            </p>

            <h2>Ending your use</h2>
            <p>
                You may stop using the service at any time. We may suspend an account that breaks these terms. Records of
                checks already run are kept as described in our <a href="/privacy">privacy policy</a>.
            </p>

            <h2>Law and changes</h2>
            <p>
                These terms are governed by the laws of the Federal Republic of Nigeria. We may update them, and will change
                the date at the top when we do. Questions: <Contact email={contactEmail} />.
            </p>
        </LegalPage>
    );
}
