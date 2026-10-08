<?php

declare(strict_types=1);

namespace App\Domain\AreaCapture\Actions;

use App\Domain\AreaCapture\Models\AreaFeature;
use App\Domain\AreaCapture\Models\AreaVerificationTask;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An officer's verdict that a feature is not there, or cannot be checked today.
 *
 * Verified and reclassified are revisions (the officer's own drawing and
 * answers) and go through CaptureAreaFeature. These two have no shape to
 * record, so they come here, through the same sync queue, and only from the
 * officer the task was given to. Nothing is deleted: a rejected feature is
 * marked so, and stays.
 */
final class RecordAreaOutcome
{
    /**
     * @param  array<string, mixed>  $input  feature_uuid, outcome (rejected or needs_revisit), notes
     */
    public function __invoke(array $input, User $officer): AreaVerificationTask
    {
        $outcome = (string) ($input['outcome'] ?? '');

        if (! in_array($outcome, ['rejected', 'needs_revisit'], true)) {
            throw ValidationException::withMessages(['outcome' => 'Rejected or needs a revisit.']);
        }

        $feature = AreaFeature::query()->where('client_uuid', (string) ($input['feature_uuid'] ?? ''))->first()
            ?? throw ValidationException::withMessages(['feature_uuid' => 'That feature does not exist.']);

        $task = AreaVerificationTask::query()->open()
            ->where('area_feature_id', $feature->id)
            ->where('assigned_to', $officer->id)
            ->first() ?? throw ValidationException::withMessages(['feature_uuid' => 'You were not sent to check that feature.']);

        return DB::transaction(function () use ($task, $feature, $outcome, $officer, $input): AreaVerificationTask {
            $notes = is_string($input['notes'] ?? null) ? mb_substr($input['notes'], 0, 2000) : null;

            $task->forceFill([
                'status' => AreaVerificationTask::STATUS_DONE,
                'outcome' => $outcome,
                'notes' => $notes,
                'resolved_at' => now(),
            ])->save();

            $feature->forceFill([
                'verification_status' => $outcome === 'rejected'
                    ? AreaFeature::VERIFICATION_REJECTED
                    : AreaFeature::VERIFICATION_NEEDS_REVISIT,
            ])->save();

            VerificationEvent::record($feature, 'area_feature.'.$outcome, $officer, array_filter([
                'task_id' => $task->id,
                'notes' => $notes,
            ], static fn (mixed $v): bool => $v !== null));

            return $task;
        });
    }
}
