<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Actions;

use App\Domain\Campaign\Enums\StakeholderCategory;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\CampaignField;
use App\Domain\Campaign\Models\CampaignStakeholder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Everything a campaign is, gathered for a screen.
 *
 * This class never reads campaign_commercials. Not conditionally, not behind a
 * flag: there is no code path from here to the contract value, so a client
 * screen built on this cannot leak one however carelessly it is assembled
 * downstream. The super admin's commercial tab loads that relationship itself,
 * on a page a client can never reach.
 *
 * `$includeInternal` governs one thing only, the stakeholders a client is not
 * shown, and it filters in SQL rather than after loading. A contact we are
 * keeping to ourselves must not be in the result set at all: filtering a loaded
 * collection leaves the row in memory and one careless prop away from the page.
 */
final class AssembleCampaignDossier
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(Campaign $campaign, bool $includeInternal = false): array
    {
        $campaign->loadMissing('organisation');

        return [
            'id' => $campaign->id,
            'code' => $campaign->code,
            'name' => $campaign->name,
            'subjectType' => $campaign->subject_type,
            'about' => $campaign->about,
            'objective' => $campaign->objective,
            'status' => $campaign->status->value,
            'statusLabel' => $campaign->status->label(),
            'isLive' => $campaign->status->isLive(),
            'client' => [
                'id' => $campaign->organisation?->id,
                'name' => $campaign->organisation?->name,
                'shortCode' => $campaign->organisation?->short_code,
            ],

            'timeline' => $this->timeline($campaign),
            'collection' => $this->collection($campaign),
            'coverage' => $this->coverage($campaign),
            'deployment' => $this->deployment($campaign),
            'schema' => $this->schema($campaign),
            'stakeholders' => $this->stakeholders($campaign, $includeInternal),
        ];
    }

    /**
     * Dates, and where today sits between them.
     *
     * Every value here is computed in the application's timezone against the
     * start of the day, so the number does not step early for somebody loading
     * the page at half past eleven at night.
     *
     * @return array<string, mixed>
     */
    private function timeline(Campaign $campaign): array
    {
        $today = Carbon::now(config('app.timezone'))->startOfDay();

        return [
            'startsOn' => $campaign->starts_on?->toDateString(),
            'endsOn' => $campaign->ends_on?->toDateString(),
            'daysElapsed' => $campaign->starts_on === null
                ? null
                : max(0, (int) $campaign->starts_on->copy()->startOfDay()->diffInDays($today, false)),
            'daysRemaining' => $campaign->daysRemaining(),
            'elapsedPercent' => $campaign->elapsedPercent(),
            // Past its end date and still running is a fact worth surfacing
            // rather than a negative number nobody reads.
            'overrun' => $campaign->ends_on !== null
                && $campaign->status->isLive()
                && $today->isAfter($campaign->ends_on->copy()->startOfDay()),
        ];
    }

    /**
     * Records gathered against the target.
     *
     * Counted through the mandates this campaign owns, which is what makes the
     * number real: it is the same structures table the field platform writes to
     * and the console reviews, not a tally kept alongside it that can drift.
     *
     * @return array<string, mixed>
     */
    private function collection(Campaign $campaign): array
    {
        $row = DB::selectOne(<<<'SQL'
            select
                count(*) filter (where structures.status <> 'rejected') as gathered,
                count(*) filter (where structures.status = 'accepted') as accepted
            from structures
            join coverage_areas on coverage_areas.id = structures.coverage_area_id
            where coverage_areas.campaign_id = ?
        SQL, [$campaign->id]);

        $gathered = $row === null ? 0 : (int) $row->gathered;
        $target = $campaign->target_record_count;

        return [
            'gathered' => $gathered,
            'accepted' => $row === null ? 0 : (int) $row->accepted,
            'target' => $target,
            'percent' => $target === null || $target === 0
                ? null
                : (int) min(100, round(($gathered / $target) * 100)),
        ];
    }

    /**
     * The ground, and its outline where one is drawn.
     *
     * The boundary comes back simplified. A mandate polygon is thousands of
     * vertices at full resolution and the client's map draws it at a few hundred
     * pixels wide, so shipping the original would be megabytes to render
     * something no eye could tell apart. Simplified in PostGIS, like everything
     * else spatial here.
     *
     * @return array<string, mixed>
     */
    private function coverage(Campaign $campaign): array
    {
        $rows = DB::select(<<<'SQL'
            select
                areas.id,
                areas.name,
                areas.lga_code,
                areas.target_record_count,
                state.name as state,
                lga.name as lga,
                round((ST_Area(areas.boundary::geography) / 1e6)::numeric, 1) as area_km2,
                (select count(*) from grid_cells where coverage_area_id = areas.id) as cells,
                ST_AsGeoJSON(ST_SimplifyPreserveTopology(areas.boundary, 0.001)) as outline
            from coverage_areas areas
            left join admin_boundaries lga on lga.id = areas.admin_boundary_id
            left join admin_boundaries state on state.id = lga.parent_id
            where areas.campaign_id = ?
            order by areas.name
        SQL, [$campaign->id]);

        $areas = array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'state' => $row->state,
            'lga' => $row->lga,
            'lgaCode' => $row->lga_code,
            'areaKm2' => (float) $row->area_km2,
            'cells' => (int) $row->cells,
            'targetRecordCount' => $row->target_record_count === null
                ? null
                : (int) $row->target_record_count,
            'outline' => $row->outline === null
                ? null
                : json_decode((string) $row->outline, true, 512, JSON_THROW_ON_ERROR),
        ], $rows);

        return [
            'areas' => $areas,
            'areaCount' => count($areas),
            'states' => array_values(array_unique(array_filter(
                array_map(static fn (array $area): ?string => $area['state'], $areas),
            ))),
        ];
    }

    /**
     * Who is out on this exercise.
     *
     * @return array<string, mixed>
     */
    private function deployment(Campaign $campaign): array
    {
        $rows = DB::select(<<<'SQL'
            select
                users.id, users.name, users.staff_ref,
                deployments.assigned_at,
                areas.name as area_name,
                (select count(*) from assignments
                  where assignments.user_id = users.id and assignments.closed_at is null) as open_cells
            from campaign_agent_assignments deployments
            join users on users.id = deployments.user_id
            left join coverage_areas areas on areas.id = deployments.coverage_area_id
            where deployments.campaign_id = ?
              and deployments.unassigned_at is null
              and deployments.status = 'active'
            order by users.name
        SQL, [$campaign->id]);

        return [
            'activeCount' => count($rows),
            'roster' => array_map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'staffRef' => $row->staff_ref,
                'area' => $row->area_name,
                'openCells' => (int) $row->open_cells,
                'assignedAt' => Carbon::parse((string) $row->assigned_at)->toDateString(),
            ], $rows),
        ];
    }

    /**
     * What the exercise says it is collecting.
     *
     * @return array<string, mixed>
     */
    private function schema(Campaign $campaign): array
    {
        $fields = CampaignField::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('sort_order')
            ->get();

        return [
            'fieldCount' => $fields->count(),
            'requiredCount' => $fields->where('is_required', true)->count(),
            'fields' => $fields->map(static fn (CampaignField $field): array => [
                'id' => $field->id,
                'label' => $field->label,
                'key' => $field->key,
                'type' => $field->type->value,
                'typeLabel' => $field->type->label(),
                'isRequired' => $field->is_required,
                'options' => $field->options,
                'helpText' => $field->help_text,
            ])->values()->all(),
        ];
    }

    /**
     * The stakeholder register, grouped the way the work is sequenced.
     *
     * @return array<string, mixed>
     */
    private function stakeholders(Campaign $campaign, bool $includeInternal): array
    {
        $query = CampaignStakeholder::query()->where('campaign_id', $campaign->id);

        if (! $includeInternal) {
            $query->visibleToClient();
        }

        $rows = $query->orderBy('category')->orderBy('name')->get();

        $byCategory = [];

        foreach ($rows as $stakeholder) {
            $byCategory[$stakeholder->category->value][] = [
                'id' => $stakeholder->id,
                'name' => $stakeholder->name,
                'organisation' => $stakeholder->organisation,
                'roleTitle' => $stakeholder->role_title,
                'contactPerson' => $stakeholder->contact_person,
                'engagementStatus' => $stakeholder->engagement_status->value,
                'engagementLabel' => $stakeholder->engagement_status->label(),
                'notes' => $stakeholder->notes,
                'visibleToClient' => $stakeholder->visible_to_client,
            ];
        }

        return [
            'total' => $rows->count(),
            'byCategory' => array_map(
                static fn (string $category): array => [
                    'category' => $category,
                    'label' => StakeholderCategory::from($category)->label(),
                    'people' => $byCategory[$category],
                    'count' => count($byCategory[$category]),
                ],
                array_keys($byCategory),
            ),
        ];
    }
}
