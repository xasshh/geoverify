<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domain\Media\Models\Media;
use App\Domain\Registry\Actions\BuildCorrectionQueue;
use App\Domain\Registry\Actions\DecideCorrection;
use App\Domain\Registry\Enums\CorrectionStatus;
use App\Domain\Registry\Models\CorrectionProposal;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Corrections a supervisor has to rule on.
 *
 * A third queue, beside observation review and claims, because it asks a third
 * question. Review asks whether a capture is sound. Claims ask whether a person
 * is who they say. This asks whether a business is right about itself, which it
 * usually is and occasionally very much is not.
 *
 * Same discipline as the other two: ordered so the person waiting longest is
 * first, a decision that cannot be recorded without a reason, and an append to
 * verification_events either way.
 */
final class CorrectionReviewController
{
    public function index(BuildCorrectionQueue $queue): Response
    {
        return Inertia::render('console/Corrections', [
            'corrections' => $this->withEvidence($queue()),
        ]);
    }

    public function decide(
        Request $request,
        CorrectionProposal $proposal,
        DecideCorrection $decide,
    ): RedirectResponse {
        $data = $request->validate([
            'decision' => ['required', 'in:accepted,rejected'],
            // Required on both paths, and shown to the party. A rejection with
            // no reason is a business told no by a system.
            'note' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        try {
            $decide($proposal, $this->reviewer(), CorrectionStatus::from($data['decision']), $data['note']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['decision' => $e->getMessage()]);
        }

        return back()->with('status', 'Correction decided.');
    }

    /**
     * The supporting document, where one was uploaded.
     *
     * Fetched here rather than in the queue builder because it needs a signed
     * URL per row, and the queue is SQL that has no business minting those.
     *
     * @param  list<array<string, mixed>>  $corrections
     * @return list<array<string, mixed>>
     */
    private function withEvidence(array $corrections): array
    {
        return array_map(static function (array $correction): array {
            if ($correction['hasEvidence'] !== true) {
                return [...$correction, 'evidenceUrl' => null];
            }

            $media = Media::query()
                ->where('mediable_type', 'App\\Domain\\Registry\\Models\\Enterprise')
                ->where('mediable_id', $correction['enterprise']['id'])
                ->where('kind', Media::KIND_DOCUMENT)
                ->latest('id')
                ->first();

            return [
                ...$correction,
                'evidenceUrl' => $media?->temporaryUrl(30),
            ];
        }, $corrections);
    }

    private function reviewer(): User
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user;
    }
}
