<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Campaign\Actions\ConfigureCapture;
use App\Domain\Campaign\Actions\ManageFeatureClasses;
use App\Domain\Campaign\Enums\GeometryType;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\FeatureClass;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * A campaign's capture modes and its feature class catalogue.
 *
 * Its own controller beside CampaignBuildController, because the catalogue is
 * versioned and the rules about what may change once capture has started live
 * in ManageFeatureClasses rather than in a general update.
 */
final class CampaignCaptureController
{
    public function settings(Request $request, Campaign $campaign, ConfigureCapture $configure): RedirectResponse
    {
        Gate::authorize('update', $campaign);

        $data = $request->validate([
            'capture_modes' => ['required', 'array', 'min:1'],
            'capture_modes.*' => ['string', Rule::in(['buildings', 'area_features'])],
            'min_mapping_unit_ha' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'field_max_accuracy_m' => ['nullable', 'integer', 'min:1', 'max:200'],
            'verification_sample_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
            'boundary_tolerance_m' => ['nullable', 'integer', 'min:0', 'max:5000'],
        ]);

        $configure($campaign, $data, self::actor($request));

        return back()->with('status', 'Capture settings saved.');
    }

    /** Adds a class to this campaign's catalogue, or revises one of its own. */
    public function saveClass(Request $request, Campaign $campaign, ManageFeatureClasses $classes): RedirectResponse
    {
        Gate::authorize('update', $campaign);

        $data = self::validateClass($request);
        $actor = self::actor($request);

        if (($data['id'] ?? null) !== null) {
            $class = FeatureClass::query()
                ->where('campaign_id', $campaign->id)
                ->findOrFail($data['id']);

            $classes->revise($class, $data, $actor);

            return back()->with('status', "{$class->label} saved.");
        }

        $class = $classes->create($campaign, $data, $actor);

        return back()->with('status', "{$class->label} added.");
    }

    /** Copies any templates this campaign does not have yet. */
    public function copyTemplates(Request $request, Campaign $campaign, ManageFeatureClasses $classes): RedirectResponse
    {
        Gate::authorize('update', $campaign);

        $copied = $classes->copyTemplates($campaign, self::actor($request));

        return back()->with('status', $copied === 0
            ? 'This campaign already has every template.'
            : "{$copied} ".($copied === 1 ? 'class' : 'classes').' copied from the templates.');
    }

    /**
     * @return array{id?: int|null, key: string, label: string, geometry_type: string, attributes?: array<int, mixed>, style?: array<string, string>|null, exclusivity_group?: string|null, description?: string|null, is_active?: bool, sort_order?: int}
     */
    public static function validateClass(Request $request): array
    {
        /** @var array{id?: int|null, key: string, label: string, geometry_type: string, attributes?: array<int, mixed>, style?: array<string, string>|null, exclusivity_group?: string|null, description?: string|null, is_active?: bool, sort_order?: int} $data */
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'key' => ['required', 'string', 'max:48', 'regex:/^[a-z][a-z0-9_]*$/'],
            'label' => ['required', 'string', 'max:120'],
            'geometry_type' => ['required', Rule::enum(GeometryType::class)],
            'description' => ['nullable', 'string', 'max:500'],
            'exclusivity_group' => ['nullable', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_]*$/'],
            'style' => ['nullable', 'array'],
            'style.fill' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'style.stroke' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'style.icon' => ['nullable', 'string', 'max:24', 'regex:/^[a-z_]+$/'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            // Checked in depth by NormaliseAttributeSchema; here only the shape.
            'attributes' => ['present', 'array', 'max:40'],
        ]);

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        }

        return $data;
    }

    private static function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
