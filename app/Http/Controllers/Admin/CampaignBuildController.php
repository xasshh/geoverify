<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Campaign\Enums\CampaignFieldType;
use App\Domain\Campaign\Enums\DeploymentStatus;
use App\Domain\Campaign\Enums\EngagementStatus;
use App\Domain\Campaign\Enums\StakeholderCategory;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\CampaignAgentAssignment;
use App\Domain\Campaign\Models\CampaignField;
use App\Domain\Campaign\Models\CampaignStakeholder;
use App\Domain\Coverage\Models\CoverageArea;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The parts a campaign is assembled from: scope, schema, stakeholders, people.
 *
 * Separate from CampaignController so that the screen editing a stakeholder is
 * not posting to the same endpoint as the screen that prices the exercise. The
 * commercial path stays one method in one controller, which is easier to keep
 * an eye on than a general update that takes whatever it is given.
 */
final class CampaignBuildController
{
    /** Bring a mandate under this campaign, or set its share of the target. */
    public function saveArea(Request $request, Campaign $campaign): RedirectResponse
    {
        Gate::authorize('update', $campaign);

        $data = $request->validate([
            'coverage_area_id' => ['required', 'integer', 'exists:coverage_areas,id'],
            'target_record_count' => ['nullable', 'integer', 'min:1', 'max:10000000'],
        ]);

        $area = CoverageArea::query()->findOrFail($data['coverage_area_id']);

        if ($area->campaign_id !== null && $area->campaign_id !== $campaign->id) {
            throw ValidationException::withMessages([
                'coverage_area_id' => 'That ground is already contracted under another campaign.',
            ]);
        }

        $area->update([
            'campaign_id' => $campaign->id,
            'target_record_count' => $data['target_record_count'] ?? $area->target_record_count,
        ]);

        return back()->with('status', "{$area->name} added to the scope.");
    }

    /**
     * Take a mandate back out of scope.
     *
     * The mandate itself is untouched: its grid, its map packs and every
     * structure captured inside it stay exactly where they are. Only the link
     * to this campaign is cleared.
     */
    public function removeArea(Campaign $campaign, CoverageArea $coverageArea): RedirectResponse
    {
        Gate::authorize('update', $campaign);

        if ($coverageArea->campaign_id === $campaign->id) {
            $coverageArea->update(['campaign_id' => null]);
        }

        return back()->with('status', 'Removed from scope.');
    }

    public function saveField(Request $request, Campaign $campaign): RedirectResponse
    {
        Gate::authorize('update', $campaign);

        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'label' => ['required', 'string', 'max:120'],
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'type' => ['required', Rule::enum(CampaignFieldType::class)],
            'options' => ['nullable', 'array', 'max:60'],
            'options.*' => ['string', 'max:120'],
            'is_required' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'help_text' => ['nullable', 'string', 'max:300'],
        ]);

        $type = CampaignFieldType::from($data['type']);

        // A choice field with no choices is a text box that lies about itself.
        if ($type->takesOptions() && ($data['options'] ?? []) === []) {
            throw ValidationException::withMessages([
                'options' => 'A choice field needs at least one option.',
            ]);
        }

        CampaignField::query()->updateOrCreate(
            ['campaign_id' => $campaign->id, 'key' => $data['key']],
            [
                'label' => $data['label'],
                'type' => $type,
                'options' => $type->takesOptions() ? array_values($data['options'] ?? []) : null,
                'is_required' => $request->boolean('is_required'),
                'sort_order' => $data['sort_order'] ?? 0,
                'help_text' => $data['help_text'] ?? null,
            ],
        );

        return back()->with('status', 'Schema saved.');
    }

    public function removeField(Campaign $campaign, CampaignField $field): RedirectResponse
    {
        Gate::authorize('update', $campaign);

        if ($field->campaign_id === $campaign->id) {
            $field->delete();
        }

        return back()->with('status', 'Field removed.');
    }

    public function saveStakeholder(Request $request, Campaign $campaign): RedirectResponse
    {
        Gate::authorize('update', $campaign);

        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::enum(StakeholderCategory::class)],
            'organisation' => ['nullable', 'string', 'max:160'],
            'role_title' => ['nullable', 'string', 'max:120'],
            'contact_person' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'engagement_status' => ['required', Rule::enum(EngagementStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'visible_to_client' => ['nullable', 'boolean'],
        ]);

        $attributes = [
            ...array_diff_key($data, array_flip(['id', 'visible_to_client'])),
            'visible_to_client' => $request->boolean('visible_to_client'),
        ];

        if (($data['id'] ?? null) !== null) {
            CampaignStakeholder::query()
                ->where('campaign_id', $campaign->id)
                ->whereKey($data['id'])
                ->firstOrFail()
                ->update($attributes);
        } else {
            CampaignStakeholder::query()->create([...$attributes, 'campaign_id' => $campaign->id]);
        }

        return back()->with('status', 'Stakeholder saved.');
    }

    public function removeStakeholder(Campaign $campaign, CampaignStakeholder $stakeholder): RedirectResponse
    {
        Gate::authorize('update', $campaign);

        if ($stakeholder->campaign_id === $campaign->id) {
            $stakeholder->delete();
        }

        return back()->with('status', 'Stakeholder removed.');
    }

    public function deploy(Request $request, Campaign $campaign): RedirectResponse
    {
        Gate::authorize('manageDeployment', $campaign);

        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'coverage_area_id' => ['nullable', 'integer', 'exists:coverage_areas,id'],
        ]);

        foreach ($data['user_ids'] as $userId) {
            $officer = User::query()->findOrFail($userId);

            if (! $officer->capturesInTheField()) {
                throw ValidationException::withMessages([
                    'user_ids' => "{$officer->name} is not an active field officer.",
                ]);
            }

            // The partial unique index makes a second live row impossible, so
            // re-deploying somebody already out is a no-op rather than an error.
            CampaignAgentAssignment::query()->firstOrCreate(
                ['campaign_id' => $campaign->id, 'user_id' => $officer->id, 'unassigned_at' => null],
                [
                    'coverage_area_id' => $data['coverage_area_id'] ?? null,
                    'assigned_at' => Carbon::now(config('app.timezone')),
                    'status' => DeploymentStatus::Active,
                    'assigned_by' => Auth::guard('web')->id(),
                ],
            );
        }

        return back()->with('status', 'Deployed.');
    }

    /** Off the campaign, and never removed from its history. */
    public function standDown(Campaign $campaign, CampaignAgentAssignment $deployment): RedirectResponse
    {
        Gate::authorize('manageDeployment', $campaign);

        if ($deployment->campaign_id === $campaign->id && $deployment->unassigned_at === null) {
            $deployment->update([
                'status' => DeploymentStatus::StoodDown,
                'unassigned_at' => Carbon::now(config('app.timezone')),
            ]);
        }

        return back()->with('status', 'Stood down.');
    }
}
