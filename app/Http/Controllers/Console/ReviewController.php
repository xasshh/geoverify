<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domain\Registry\Models\Structure;
use App\Domain\Registry\Models\StructureObservation;
use App\Domain\Verification\Actions\AssembleReviewRecord;
use App\Domain\Verification\Actions\BuildReviewQueue;
use App\Domain\Verification\Actions\ReviewObservation;
use App\Domain\Verification\Enums\ReviewDecision;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The review queue, and the decision taken on one capture.
 */
final class ReviewController
{
    public function index(Request $request, BuildReviewQueue $queue): Response
    {
        Gate::authorize('viewAny', StructureObservation::class);

        $officerId = $request->integer('officer') ?: null;

        $built = $queue(50, $request->integer('area') ?: null, $officerId);

        return Inertia::render('console/Review', [
            'queue' => $built['rows'],
            'awaiting' => $built['awaiting'],
            'officers' => $this->officers(),
            'filters' => ['officer' => $officerId],
        ]);
    }

    public function show(StructureObservation $observation, AssembleReviewRecord $record): Response
    {
        Gate::authorize('view', $observation);

        return Inertia::render('console/ReviewRecord', [
            'record' => $record($observation),
            'canDecide' => Gate::allows('review', $observation),
        ]);
    }

    public function decide(
        Request $request,
        StructureObservation $observation,
        ReviewObservation $review,
    ): RedirectResponse {
        Gate::authorize('review', $observation);

        $validated = $request->validate([
            'decision' => ['required', Rule::enum(ReviewDecision::class)],
            // The officer has to be able to act on it, so it is not optional
            // and it is not a checkbox. What was wrong has to be said.
            'reason' => ['nullable', 'string', 'min:8', 'max:280'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $decision = ReviewDecision::from($validated['decision']);

        /** @var User $supervisor */
        $supervisor = $request->user();

        try {
            $review(
                $observation,
                $decision,
                $supervisor,
                $validated['reason'] ?? null,
                $validated['note'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()]);
        }

        return redirect()
            ->route('console.review.index')
            ->with('status', $decision->label().': '.($observation->structure_type));
    }

    /**
     * The officers with work in the queue, so the filter offers only names that
     * would return something.
     *
     * @return list<array{id: int, name: string}>
     */
    private function officers(): array
    {
        return User::query()
            ->whereIn('id', StructureObservation::query()
                ->where('status', Structure::STATUS_SUBMITTED)
                ->select('captured_by'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->all();
    }
}
