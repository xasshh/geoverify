<?php

declare(strict_types=1);

namespace App\Http\Controllers\Field;

use App\Domain\Coverage\Models\GridCell;
use App\Domain\Field\Models\Assignment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the officer sees when they open the app: their work, and nothing else.
 *
 * Scoped by the authenticated user rather than by a request parameter, so there
 * is no identifier to tamper with.
 */
final class AssignmentBoardController
{
    public function index(Request $request): Response
    {
        $officer = $request->user();

        $assignments = Assignment::query()
            ->with(['gridCell:id,h3_index,footprint_count,structures_captured,coverage_area_id',
                'gridCell.coverageArea:id,name,client_name'])
            ->where('user_id', $officer->id)
            ->whereNull('closed_at')
            ->orderByRaw('due_on nulls last')
            ->get();

        return Inertia::render('field/Assignments', [
            'officer' => [
                'name' => $officer->name,
                'staffRef' => $officer->staff_ref,
            ],
            'assignments' => $assignments->map(static fn (Assignment $a): array => [
                'id' => $a->id,
                'h3' => $a->gridCell instanceof GridCell ? $a->gridCell->h3() : '',
                'mandate' => $a->gridCell?->coverageArea->name ?? '',
                'coverageAreaId' => (int) ($a->gridCell->coverage_area_id ?? 0),
                'footprints' => (int) ($a->gridCell->footprint_count ?? 0),
                'captured' => (int) ($a->gridCell->structures_captured ?? 0),
                'status' => $a->status->value,
                'statusLabel' => $a->status->label(),
                'dueOn' => $a->due_on?->toDateString(),
                'overdue' => $a->isOverdue(),
                'returnReason' => $a->return_reason,
            ])->all(),
        ]);
    }
}
