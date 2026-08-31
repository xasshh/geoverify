<?php

declare(strict_types=1);

namespace App\Domain\Coverage\Actions;

use App\Domain\Coverage\Models\AdminBoundary;
use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A mandate, from a loaded administrative boundary.
 *
 * Extracted from geoverify:coverage-create so the admin screen and the command
 * create mandates the same way rather than two ways that drift. The command
 * still owns its own output; this owns what actually happens.
 *
 * The boundary is copied from admin_boundaries rather than referenced, because a
 * mandate is contractual: if a later data release moves the LGA line, the ground
 * a client contracted for must not silently move with it.
 */
final class CreateCoverageArea
{
    public function __invoke(
        string $lgaCode,
        string $client,
        ?string $name = null,
        ?string $contractRef = null,
        int $accuracyThresholdM = 15,
        int $resolution = 9,
        ?User $actor = null,
    ): CoverageArea {
        $lga = AdminBoundary::query()
            ->where('level', AdminBoundary::LEVEL_LGA)
            ->where('code', $lgaCode)
            ->first();

        if (! $lga instanceof AdminBoundary) {
            throw new RuntimeException(
                "No LGA loaded with code {$lgaCode}. Load the boundaries first.",
            );
        }

        // Nullable by construction: a state has no parent, and an LGA that
        // failed hierarchy resolution has none either.
        $state = AdminBoundary::query()->find($lga->parent_id);

        $attributes = [
            'name' => $name ?? $lga->name,
            'contract_ref' => $contractRef,
            'state_code' => $state?->code,
            'admin_boundary_id' => $lga->id,
            'status' => 'active',
            'accuracy_threshold_m' => $accuracyThresholdM,
            'default_h3_resolution' => $resolution,
        ];

        return DB::transaction(function () use ($lga, $lgaCode, $client, $attributes, $actor): CoverageArea {
            $area = CoverageArea::query()
                ->where('lga_code', $lgaCode)
                ->where('client_name', $client)
                ->first();

            $existed = $area instanceof CoverageArea;

            if ($area instanceof CoverageArea) {
                $area->fill($attributes)->save();
            } else {
                // The boundary is NOT NULL, so it has to be written in the same
                // statement as the row.
                $id = DB::scalar(
                    'INSERT INTO coverage_areas (
                        client_name, contract_ref, name, state_code, lga_code, admin_boundary_id,
                        status, accuracy_threshold_m, default_h3_resolution, boundary, created_at, updated_at
                     )
                     SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, ab.boundary, now(), now()
                       FROM admin_boundaries ab WHERE ab.id = ?
                     RETURNING id',
                    [
                        $client,
                        $attributes['contract_ref'],
                        $attributes['name'],
                        $attributes['state_code'],
                        $lgaCode,
                        $lga->id,
                        $attributes['status'],
                        $attributes['accuracy_threshold_m'],
                        $attributes['default_h3_resolution'],
                        $lga->id,
                    ],
                );

                $area = CoverageArea::query()->findOrFail($id);
            }

            // Refresh the mandate geometry on re-run, so a corrected boundary
            // load is picked up deliberately rather than never.
            DB::statement(
                'UPDATE coverage_areas SET boundary = (SELECT boundary FROM admin_boundaries WHERE id = ?) WHERE id = ?',
                [$lga->id, $area->id],
            );

            VerificationEvent::record(
                $area,
                $existed ? 'mandate.updated' : 'mandate.created',
                $actor,
                [
                    'client' => $client,
                    'lga_code' => $lgaCode,
                    'resolution' => $attributes['default_h3_resolution'],
                ],
                $actor instanceof User
                    ? VerificationEvent::ACTOR_USER
                    : VerificationEvent::ACTOR_SYSTEM,
            );

            return $area->refresh();
        });
    }
}
