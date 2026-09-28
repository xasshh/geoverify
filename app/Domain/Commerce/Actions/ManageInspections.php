<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\Protection;
use App\Domain\Commerce\Enums\PurchaseStatus;
use App\Domain\Commerce\Models\Inspection;
use App\Domain\Commerce\Models\PurchaseOrder;
use App\Domain\Field\Actions\FieldMessaging;
use App\Domain\Field\Enums\AssignmentStatus;
use App\Domain\Field\Models\Assignment;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Actions\ResolveStructureCell;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * An inspection or site visit, from the buyer's request to the buyer's word.
 *
 * The agent's side reaches the field client as an assignment of kind
 * `inspection`, created here beside the field code rather than inside it, the
 * way AssignVerificationVisit sends a paid visit: nothing about sweeping or
 * capturing changes. The report is its own record, written once.
 *
 * Every step appends to verification_events against the order, so the order's
 * timeline tells the whole story without a second log.
 */
final class ManageInspections
{
    public function __construct(
        private readonly FieldMessaging $messages,
        private readonly ManagePurchase $purchases,
        private readonly ResolveStructureCell $cells,
    ) {}

    /** Written with the order, for an order that bought a service. */
    public function request(PurchaseOrder $order, ?Carbon $requestedFor = null, ?string $visitMode = null): ?Inspection
    {
        if ($order->protection === Protection::None) {
            return null;
        }

        $visit = $order->protection === Protection::SiteVisit;

        if ($visit && ! in_array($visitMode, ['with_me', 'for_me'], true)) {
            throw new RuntimeException('Say whether the agent goes with you or for you.');
        }

        if ($visit && ($requestedFor === null || $requestedFor->isPast())) {
            throw new RuntimeException('Choose a time for the visit that has not passed.');
        }

        return Inspection::query()->create([
            'purchase_order_id' => $order->id,
            'kind' => $visit ? Inspection::KIND_SITE_VISIT : Inspection::KIND_INSPECTION,
            'status' => Inspection::REQUESTED,
            'requested_for' => $visit ? $requestedFor : null,
            'visit_mode' => $visit ? $visitMode : null,
        ]);
    }

    /** A supervisor sends an agent. The job appears on the agent's device as an assignment. */
    public function assign(Inspection $inspection, User $agent, User $supervisor): Inspection
    {
        if (! $supervisor->supervises()) {
            throw new RuntimeException('Only a supervisor sends an agent.');
        }

        if (! $agent->capturesInTheField()) {
            throw new RuntimeException("{$agent->name} is not an active field officer.");
        }

        return DB::transaction(function () use ($inspection, $agent, $supervisor): Inspection {
            /** @var Inspection $fresh */
            $fresh = Inspection::query()->with('order.enterprise.structure')->whereKey($inspection->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== Inspection::REQUESTED && $fresh->status !== Inspection::ASSIGNED) {
                throw new RuntimeException('That job already has a report.');
            }

            $order = $fresh->order ?? throw new RuntimeException('That job has no order.');

            if ($order->status !== PurchaseStatus::Held) {
                throw new RuntimeException('Only a paid order that is waiting to be sent is inspected.');
            }

            $structure = $order->enterprise->structure ?? throw new RuntimeException('That business has no building on the register.');

            // Reassigning: the old agent's job is closed, not deleted.
            if ($fresh->assignment_id !== null) {
                Assignment::query()->whereKey($fresh->assignment_id)->whereNull('closed_at')
                    ->update(['status' => AssignmentStatus::Reassigned->value, 'closed_at' => now()]);
            }

            $cellId = ($this->cells)($structure);

            $assignment = Assignment::query()->create([
                'grid_cell_id' => $cellId,
                'structure_id' => $structure->id,
                'kind' => Assignment::KIND_INSPECTION,
                // Above a sweep, like a paid visit: somebody's money is held
                // until this is done.
                'priority' => 2,
                'user_id' => $agent->id,
                'assigned_by' => $supervisor->id,
                'assigned_at' => now(),
                'due_on' => ($fresh->requested_for ?? now())->toDateString(),
                'status' => AssignmentStatus::Assigned,
            ]);

            $fresh->update([
                'status' => Inspection::ASSIGNED,
                'assignment_id' => $assignment->id,
                'agent_id' => $agent->id,
                'assigned_at' => now(),
            ]);

            VerificationEvent::record($order, 'purchase.inspection_assigned', $supervisor, [
                'inspection_id' => $fresh->id,
                'agent_id' => $agent->id,
                'agent_ref' => $agent->staff_ref,
            ]);

            $when = $fresh->requested_for === null ? 'today' : $fresh->requested_for->timezone(config('app.timezone'))->format('j M, g:i a');
            $this->messages->toOfficer($supervisor, $agent, sprintf(
                '%s at %s, %s. Order %s.',
                $fresh->label(),
                $order->enterprise->trading_name ?? 'the business',
                $when,
                $order->reference,
            ));

            return $fresh;
        });
    }

    /**
     * The agent says they are there. How far the handset is from the premises
     * is measured in PostGIS against the building's own centroid; the parties
     * are told whether it matched, never where either point is.
     */
    public function arrive(Inspection $inspection, User $agent, float $longitude, float $latitude, ?float $accuracyM): Inspection
    {
        $this->assertAgent($inspection, $agent);

        if ($inspection->arrived_at !== null) {
            return $inspection;
        }

        $structureId = $inspection->order?->enterprise?->structure_id;

        $distance = DB::selectOne(
            'SELECT ST_Distance(centroid, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS m FROM structures WHERE id = ?',
            [$longitude, $latitude, $structureId],
        );

        DB::update(
            'UPDATE inspections SET arrived_at = now(), arrival_distance_m = ?, arrival_accuracy_m = ?,
                    arrival_position = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, updated_at = now()
              WHERE id = ?',
            [round((float) ($distance->m ?? 0), 1), $accuracyM, $longitude, $latitude, $inspection->id],
        );

        return $inspection->refresh();
    }

    /**
     * The report, once. Idempotent on the handset's uuid, so a submit retried
     * over a bad connection is one report.
     *
     * @param  array<string, array{passed: bool, detail?: string|null}>  $answers
     */
    public function submit(Inspection $inspection, User $agent, string $reportUuid, array $answers, ?string $notes): Inspection
    {
        $this->assertAgent($inspection, $agent);

        if (! Str::isUuid($reportUuid)) {
            throw new RuntimeException('A report needs the uuid the handset gave it.');
        }

        return DB::transaction(function () use ($inspection, $agent, $reportUuid, $answers, $notes): Inspection {
            /** @var Inspection $fresh */
            $fresh = Inspection::query()->whereKey($inspection->id)->lockForUpdate()->firstOrFail();

            if ($fresh->report_uuid === $reportUuid) {
                return $fresh;
            }

            if ($fresh->status !== Inspection::ASSIGNED) {
                throw new RuntimeException('That job already has a report.');
            }

            if ($fresh->arrived_at === null) {
                throw new RuntimeException('Record your arrival before the report.');
            }

            if ($fresh->photos()->count() === 0) {
                throw new RuntimeException('A report needs at least one photograph.');
            }

            $template = Inspection::CHECKLISTS[$fresh->kind];

            if (array_diff(array_keys($template), array_keys($answers)) !== [] || array_diff(array_keys($answers), array_keys($template)) !== []) {
                throw new RuntimeException('Answer every check on the list, and only those.');
            }

            $checklist = [];

            foreach ($template as $key => $label) {
                $detail = isset($answers[$key]['detail']) ? trim((string) $answers[$key]['detail']) : '';
                $checklist[] = [
                    'key' => $key,
                    'label' => $label,
                    'passed' => (bool) $answers[$key]['passed'],
                    'detail' => $detail === '' ? null : mb_substr($detail, 0, 200),
                ];
            }

            $fresh->update([
                'status' => Inspection::SUBMITTED,
                'report_uuid' => $reportUuid,
                'checklist' => $checklist,
                'notes' => $notes === null || trim($notes) === '' ? null : mb_substr(trim($notes), 0, 2000),
                'submitted_at' => now(),
            ]);

            if ($fresh->assignment_id !== null) {
                Assignment::query()->whereKey($fresh->assignment_id)
                    ->update(['status' => AssignmentStatus::Submitted->value, 'closed_at' => now()]);
            }

            $order = $fresh->order()->firstOrFail();

            VerificationEvent::record($order, 'purchase.inspection_submitted', $agent, [
                'inspection_id' => $fresh->id,
                'passed' => count(array_filter($checklist, static fn (array $c): bool => $c['passed'])),
                'of' => count($checklist),
                'photos' => $fresh->photos()->count(),
            ]);

            return $fresh;
        });
    }

    /**
     * The buyer's word on the report. Approving lets the merchant send the
     * goods; rejecting raises an issue, and the money stays held until an
     * admin rules, exactly as any other issue does.
     */
    public function decide(Inspection $inspection, PortalAccount $buyer, bool $approve, ?string $note = null): Inspection
    {
        $order = $inspection->order()->firstOrFail();

        if ($order->buyer_account_id !== $buyer->id) {
            throw new RuntimeException('That order is not yours.');
        }

        return DB::transaction(function () use ($inspection, $order, $buyer, $approve, $note): Inspection {
            /** @var Inspection $fresh */
            $fresh = Inspection::query()->whereKey($inspection->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== Inspection::SUBMITTED) {
                throw new RuntimeException('There is no report waiting for you on that order.');
            }

            if (! $approve && ($note === null || mb_strlen(trim($note)) < 10)) {
                throw new RuntimeException('Tell us what is wrong with the report, in a sentence or two.');
            }

            $fresh->update([
                'status' => $approve ? Inspection::APPROVED : Inspection::REJECTED,
                'decided_at' => now(),
                'decision_note' => $note === null ? null : mb_substr(trim($note), 0, 500),
            ]);

            VerificationEvent::recordForBuyer($order, $approve ? 'purchase.inspection_approved' : 'purchase.inspection_rejected', $buyer, [
                'inspection_id' => $fresh->id,
                'note' => $note,
            ]);

            if (! $approve) {
                $this->purchases->raiseIssue($order, $buyer, 'The inspection report was not accepted: '.trim((string) $note));
            }

            return $fresh;
        });
    }

    /**
     * The job to hand to an agent: the nearest officer seen in the field today,
     * if any, measured in PostGIS.
     *
     * @return array{id: int, name: string, staffRef: string|null, km: float}|null
     */
    public function nearestAgent(Inspection $inspection): ?array
    {
        $structureId = $inspection->order?->enterprise?->structure_id;

        if ($structureId === null) {
            return null;
        }

        $row = DB::selectOne(<<<'SQL'
            SELECT u.id, u.name, u.staff_ref,
                   ST_Distance(s.centroid, f.point::geography) AS m
              FROM structures s
              CROSS JOIN LATERAL (
                   SELECT DISTINCT ON (fs.user_id) fs.user_id, pf.point
                     FROM position_fixes pf
                     JOIN field_sessions fs ON fs.id = pf.field_session_id
                    WHERE pf.recorded_at >= now() - interval '12 hours'
                      AND pf.is_mock = false
                    ORDER BY fs.user_id, pf.recorded_at DESC) f
              JOIN users u ON u.id = f.user_id
             WHERE s.id = ? AND u.status = 'active' AND u.role = 'officer'
             ORDER BY m
             LIMIT 1
        SQL, [$structureId]);

        return $row === null ? null : [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'staffRef' => $row->staff_ref,
            'km' => round((float) $row->m / 1000, 1),
        ];
    }

    private function assertAgent(Inspection $inspection, User $agent): void
    {
        if ($inspection->agent_id !== $agent->id) {
            throw new RuntimeException('That job is not yours.');
        }
    }
}
