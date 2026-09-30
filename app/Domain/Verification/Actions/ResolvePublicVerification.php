<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Enumerate\Actions\ScoreEnumerateRequest;
use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Enumerate\Models\EnumerateVisit;
use App\Domain\Registry\Actions\ResolveListingTier;
use App\Domain\Verification\Models\PublicVerification;

/**
 * Answers the question a QR code asks: is this certificate real, and is it still
 * true?
 *
 * Reached with no session by whoever is holding the paper. Everything it returns
 * is therefore a deliberate disclosure rather than a convenience, and the list is
 * short: the business name, where it is to ward level, what was found, when, and
 * whether that is still current.
 *
 * Not the coordinates, not the accuracy, not a photograph, not a phone number.
 * Those are in the certificate itself, which belongs to the business that paid
 * for it, and putting them on an open URL would turn every certificate ever
 * printed into a lookup service for the contents of the register.
 *
 * Two switches, and they are not the same switch. `revoked_at` kills this
 * certificate, which is a decision staff make about a document issued in error
 * or overturned on appeal. `publication_state` governs whether the business
 * appears in the public directory, which is a decision the party makes about
 * itself. A business can be absent from the directory and still have a
 * certificate somebody can check, because it bought that certificate in order
 * to show it to people.
 */
final class ResolvePublicVerification
{
    public function __construct(
        private readonly ResolveListingTier $tiers,
        private readonly ScoreEnumerateRequest $score,
    ) {}

    /**
     * @return array{
     *     state: string,
     *     reference?: string,
     *     business?: string,
     *     ward?: string|null,
     *     lga?: string|null,
     *     state_name?: string|null,
     *     finding?: string,
     *     tier?: string,
     *     verified_on?: string,
     *     valid_until?: string|null,
     *     freshness?: string,
     *     officer_reference?: string
     * }
     */
    public function __invoke(string $token): array
    {
        $verification = PublicVerification::query()
            ->with(['order', 'enterprise.structure.ward', 'enterprise.structure.lga', 'enterprise.structure.state'])
            ->where('token', $token)
            ->first();

        // One answer for "no such token" and for a malformed one. Telling the
        // difference would let somebody probe the shape of a valid token.
        if (! $verification instanceof PublicVerification) {
            $report = EnumerateRequest::query()->where('report_token', $token)->first();

            return $report === null ? ['state' => 'unknown'] : $this->report($report);
        }

        if ($verification->revoked()) {
            return ['state' => 'revoked'];
        }

        $order = $verification->order;
        $enterprise = $verification->enterprise;
        $structure = $enterprise->structure;

        $establishedOn = $order->completed_at ?? $verification->issued_at;

        $freshness = $this->tiers->freshness($establishedOn)['state'] ?? 'current';

        return [
            'state' => $verification->expired() ? 'expired' : 'valid',
            'reference' => $order->reference,
            'business' => $enterprise->trading_name,
            'ward' => $structure->ward?->name,
            'lga' => $structure->lga?->name,
            'state_name' => $structure->state?->name,
            'finding' => $order->outcome?->label() ?? 'Recorded',
            'tier' => $order->tier,
            'verified_on' => $establishedOn->toDateString(),
            'valid_until' => $verification->valid_until?->toDateString(),
            'freshness' => $freshness,

            // Who attended, without saying who they are. An officer's identity
            // on a public page is a safety problem for the officer and adds
            // nothing a reader can act on: what they need is that a named,
            // accountable person went, and that we can say which one if asked.
            'officer_reference' => $this->officerReference($order->completed_by ?? 0),
        ];
    }

    /**
     * A stable, non reversible handle for the officer on the visit.
     *
     * Derived from the application key so it cannot be reproduced by anybody
     * who does not already have our secrets, and truncated because it is a
     * reference for a support conversation rather than a credential.
     */
    /**
     * An Enumerate report, checked by whoever the requester handed it to.
     *
     * The narrowest answer that proves the paper genuine: the business as CAC
     * names it, the tier, the finding and score, when, the ward and LGA, and
     * whether the report was final. Nothing the report itself withholds, and
     * less than it shows: no registry detail, no daily log, no photographs.
     *
     * @return array<string, mixed>
     */
    private function report(EnumerateRequest $request): array
    {
        $result = ($this->score)($request);
        $site = $request->visits()
            ->where('kind', EnumerateVisit::KIND_SITE)
            ->where('status', EnumerateVisit::ACCEPTED)
            ->with(['ward', 'lga'])
            ->latest('id')
            ->first();

        $finished = $request->status->finished();
        $on = $request->completed_at ?? $request->desk_checked_at ?? $request->paid_at;

        return [
            'state' => 'valid',
            'kind' => 'report',
            'final' => $finished,
            'reference' => $request->reference,
            'business' => $request->subject_name,
            'ward' => $site?->ward?->name,
            'lga' => $site?->lga?->name,
            'state_name' => null,
            'finding' => $result['finding'],
            'score' => $result['score'],
            'tier' => $request->tier->short(),
            'verified_on' => $on->toDateString(),
            'freshness' => $finished ? ($this->tiers->freshness($on)['state'] ?? 'current') : 'current',
            'officer_reference' => $site === null ? null : $this->officerReference($site->agent_id),
        ];
    }

    private function officerReference(int $userId): string
    {
        if ($userId === 0) {
            return 'GV-0000';
        }

        $digest = hash_hmac('sha256', 'officer:'.$userId, (string) config('app.key'));

        return 'GV-'.mb_strtoupper(mb_substr($digest, 0, 4));
    }
}
