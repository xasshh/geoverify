<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use App\Domain\Coverage\Models\GridCell;
use App\Domain\Field\Enums\AssignmentStatus;
use App\Domain\Field\Models\Assignment;
use App\Domain\Registry\Data\StructureCapture;
use App\Domain\Registry\Models\Structure;
use App\Domain\Registry\Models\StructureObservation;
use App\Domain\Verification\Models\VerificationEvent;
use App\Jobs\ScoreCapture;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records a structure, or a new observation of one already known.
 *
 * Three things happen here that must not be skipped:
 *
 *  1. The point is resolved into ward, LGA and state server side. Whatever the
 *     device said about geography is ignored.
 *  2. A revisit appends an observation. The columns on structures are updated as
 *     a projection of it, and the previous observation is left exactly as it was.
 *  3. Everything appends to the audit log with the values it was based on.
 *
 * Idempotent on client_uuid, so a handset retrying a sync three times produces
 * one structure and one observation.
 */
final class CaptureStructure
{
    public function __construct(
        private readonly ResolveAdminHierarchy $hierarchy,
    ) {}

    public function capture(StructureCapture $capture, User $officer): Structure
    {
        if (! $officer->capturesInTheField()) {
            throw new RuntimeException('Only an active field officer can capture a structure.');
        }

        $this->assertHoldsCell($capture->gridCellId, $officer);

        return DB::transaction(function () use ($capture, $officer): Structure {
            $existing = Structure::query()
                ->where('client_uuid', $capture->clientUuid)
                ->lockForUpdate()
                ->first();

            $structure = $existing instanceof Structure
                ? $this->addObservation($existing, $capture, $officer)
                : $this->createStructure($capture, $officer);

            return $structure;
        });
    }

    /**
     * The officer must currently hold the ground they are capturing on.
     *
     * Checked here rather than only at the HTTP edge, because there are two ways
     * in: a live post while there is signal, and a batch of mutations synced
     * hours later from a handset that was offline. The realistic case is an
     * officer working a cell all morning with no signal while a supervisor moves
     * it to someone else, and a check that lives only in one controller would let
     * that work through the other door.
     */
    private function assertHoldsCell(int $gridCellId, User $officer): void
    {
        $holdsIt = Assignment::query()
            ->where('grid_cell_id', $gridCellId)
            ->where('user_id', $officer->id)
            ->whereNull('closed_at')
            ->whereIn('status', [
                AssignmentStatus::Assigned->value,
                AssignmentStatus::InProgress->value,
                AssignmentStatus::Returned->value,
            ])
            ->exists();

        if (! $holdsIt) {
            throw new RuntimeException(
                'That cell is not assigned to you. It may have been reassigned while you were offline.',
            );
        }
    }

    private function createStructure(StructureCapture $capture, User $officer): Structure
    {
        $cell = GridCell::query()->findOrFail($capture->gridCellId);

        // Server side, from the point itself. The device is not consulted.
        $resolved = $this->hierarchy->forPoint($capture->longitude, $capture->latitude);

        // Written in one statement: centroid is NOT NULL, so the row cannot exist
        // before its geometry does. PostGIS builds the point; no coordinate is
        // assembled in PHP.
        $id = DB::scalar(<<<'SQL'
            INSERT INTO structures (
                grid_cell_id, coverage_area_id, external_footprint_id,
                ward_id, lga_id, state_id, h3_index,
                captured_by, captured_at, capture_accuracy_m,
                structure_type, layout_class, floors, unit_count,
                condition, occupancy_status, status, client_uuid,
                centroid, footprint, created_at, updated_at
            )
            VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ST_SetSRID(ST_Point(?, ?), 4326)::geography,
                CASE WHEN ?::bigint IS NULL THEN NULL
                     ELSE (SELECT f.footprint FROM external_footprints f WHERE f.id = ?) END,
                now(), now()
            )
            RETURNING id
        SQL, [
            $cell->id,
            $cell->coverage_area_id,
            $capture->externalFootprintId,
            $resolved['ward_id'],
            $resolved['lga_id'],
            $resolved['state_id'],
            $cell->h3_index,
            $officer->id,
            $capture->observedAt,
            $capture->accuracyM,
            $capture->structureType,
            $capture->layoutClass,
            $capture->floors,
            $capture->unitCount,
            $capture->condition,
            $capture->occupancyStatus,
            Structure::STATUS_SUBMITTED,
            $capture->clientUuid,
            $capture->longitude,
            $capture->latitude,
            $capture->externalFootprintId,
            $capture->externalFootprintId,
        ]);

        $structure = Structure::query()->findOrFail($id);

        $observation = $this->writeObservation($structure, $capture, $officer);

        // A footprint the officer tapped is now accounted for.
        if ($capture->externalFootprintId !== null) {
            DB::table('external_footprints')
                ->where('id', $capture->externalFootprintId)
                ->update(['matched_structure_id' => $structure->id, 'updated_at' => now()]);
        }

        $this->refreshCellProgress($structure);

        VerificationEvent::record($structure, 'structure.captured', $officer, [
            'client_uuid' => $capture->clientUuid,
            'accuracy_m' => $capture->accuracyM,
            'resolved' => $this->hierarchy->describe([
                'ward_id' => $structure->ward_id,
                'lga_id' => $structure->lga_id,
                'state_id' => $structure->state_id,
            ]),
            'matched_footprint' => $capture->externalFootprintId !== null,
            'observation_id' => $observation->id,
        ]);

        return $structure->refresh();
    }

    /**
     * A revisit. The previous observation stays exactly as recorded; this adds a
     * new one and moves the projection forward.
     */
    private function addObservation(Structure $structure, StructureCapture $capture, User $officer): Structure
    {
        $alreadyRecorded = StructureObservation::query()
            ->where('client_uuid', $capture->observationUuid)
            ->exists();

        if ($alreadyRecorded) {
            // The same submission arriving again. One record, same answer.
            return $structure;
        }

        $before = $structure->only(['structure_type', 'floors', 'unit_count', 'condition', 'occupancy_status']);

        $observation = $this->writeObservation($structure, $capture, $officer);

        $structure->update([
            'structure_type' => $capture->structureType,
            'layout_class' => $capture->layoutClass,
            'floors' => $capture->floors,
            'unit_count' => $capture->unitCount,
            'condition' => $capture->condition,
            'occupancy_status' => $capture->occupancyStatus,
            'status' => Structure::STATUS_SUBMITTED,
        ]);

        VerificationEvent::record($structure, 'structure.re_observed', $officer, [
            'observation_id' => $observation->id,
            'previous' => $before,
            'current' => $structure->only(['structure_type', 'floors', 'unit_count', 'condition', 'occupancy_status']),
        ]);

        return $structure->refresh();
    }

    private function writeObservation(Structure $structure, StructureCapture $capture, User $officer): StructureObservation
    {
        $observation = StructureObservation::query()->create([
            'structure_id' => $structure->id,
            'captured_by' => $officer->id,
            'field_session_id' => $capture->fieldSessionId,
            'assignment_id' => $capture->assignmentId,
            'observed_at' => $capture->observedAt,
            'capture_accuracy_m' => $capture->accuracyM,
            'structure_type' => $capture->structureType,
            'layout_class' => $capture->layoutClass,
            'floors' => $capture->floors,
            'unit_count' => $capture->unitCount,
            'condition' => $capture->condition,
            'occupancy_status' => $capture->occupancyStatus,
            'notes' => $capture->notes,
            'client_uuid' => $capture->observationUuid,
        ]);

        // Scored off the request. A sync batch carries two hundred mutations and
        // the officer's connection is the one thing this must never wait on.
        ScoreCapture::dispatch($observation->id)->afterCommit();

        return $observation;
    }

    /**
     * Moves the cell's captured count and coverage. Recomputed from the table
     * rather than incremented, so a retry cannot inflate it.
     */
    private function refreshCellProgress(Structure $structure): void
    {
        DB::statement(<<<'SQL'
            UPDATE grid_cells g
               SET structures_captured = c.n,
                   coverage_pct = CASE WHEN g.footprint_count = 0 THEN 0
                                       ELSE LEAST(100, round((c.n::numeric / g.footprint_count) * 100, 2)) END,
                   updated_at = now()
              FROM (SELECT count(*) AS n FROM structures WHERE grid_cell_id = ?) c
             WHERE g.id = ?
        SQL, [$structure->grid_cell_id, $structure->grid_cell_id]);
    }
}
