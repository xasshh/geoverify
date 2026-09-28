<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domain\Commerce\Actions\ManageInspections;
use App\Domain\Commerce\Enums\PurchaseStatus;
use App\Domain\Commerce\Models\Inspection;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Inspections and site visits buyers have paid for, waiting for an agent.
 *
 * Only paid orders appear: an inspection is requested with the order, but it
 * is nobody's work until the provider says the money is held.
 */
final class InspectionController
{
    public function __construct(private readonly ManageInspections $inspections) {}

    public function index(): Response
    {
        $jobs = Inspection::query()
            ->with(['order.enterprise.structure.ward', 'agent:id,name,staff_ref'])
            ->whereIn('status', [Inspection::REQUESTED, Inspection::ASSIGNED, Inspection::SUBMITTED])
            ->whereHas('order', static fn ($q) => $q->where('status', PurchaseStatus::Held->value))
            ->orderByRaw("CASE status WHEN 'requested' THEN 0 WHEN 'assigned' THEN 1 ELSE 2 END")
            ->orderByRaw('requested_for NULLS FIRST')
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        return Inertia::render('console/Inspections', [
            'jobs' => $jobs->map(fn (Inspection $i): array => [
                'id' => $i->id,
                'label' => $i->label(),
                'status' => $i->status,
                'orderRef' => $i->order?->reference,
                'business' => $i->order?->enterprise->trading_name,
                'ward' => $i->order?->enterprise->structure->ward?->name,
                'requestedFor' => $i->requested_for?->toIso8601String(),
                'visitMode' => $i->visit_mode,
                'paidAt' => $i->order?->paid_at?->toIso8601String(),
                'agent' => $i->agent === null ? null : ['name' => $i->agent->name, 'ref' => $i->agent->staff_ref],
                'nearest' => $i->status === Inspection::REQUESTED ? $this->inspections->nearestAgent($i) : null,
            ])->all(),
            'officers' => User::query()
                ->where('role', Role::Officer->value)
                ->where('status', User::STATUS_ACTIVE)
                ->orderBy('name')
                ->get(['id', 'name', 'staff_ref'])
                ->map(static fn (User $u): array => ['id' => $u->id, 'name' => $u->name, 'ref' => $u->staff_ref])
                ->all(),
        ]);
    }

    public function assign(Request $request, Inspection $inspection): RedirectResponse
    {
        $input = $request->validate(['officer_id' => ['required', 'integer', 'exists:users,id']]);
        $supervisor = $request->user();
        abort_unless($supervisor instanceof User, 403);

        try {
            $this->inspections->assign($inspection, User::query()->findOrFail($input['officer_id']), $supervisor);
        } catch (RuntimeException $e) {
            return back()->withErrors(['officer_id' => $e->getMessage()]);
        }

        return back()->with('status', 'Sent. It is on the agent’s device now.');
    }
}
