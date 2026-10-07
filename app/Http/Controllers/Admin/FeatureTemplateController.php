<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Campaign\Actions\ManageFeatureClasses;
use App\Domain\Campaign\Enums\AttributeType;
use App\Domain\Campaign\Enums\GeometryType;
use App\Domain\Campaign\Models\FeatureClass;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The global feature class templates, kept by administrators.
 *
 * A campaign copies from here and then owns its copy, so changing a template
 * reaches the next campaign that copies it and never one already running.
 */
final class FeatureTemplateController
{
    public function index(): Response
    {
        $templates = FeatureClass::query()
            ->templates()
            ->with('latestVersion')
            ->withCount(['versions'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('admin/FeatureTemplates', [
            'templates' => $templates->map(static fn (FeatureClass $class): array => [
                'id' => $class->id,
                'key' => $class->key,
                'label' => $class->label,
                'geometryType' => $class->geometry_type->value,
                'geometryLabel' => $class->geometry_type->label(),
                'style' => $class->style,
                'exclusivityGroup' => $class->exclusivity_group,
                'description' => $class->description,
                'isActive' => $class->is_active,
                'sortOrder' => $class->sort_order,
                'version' => $class->latestVersion?->version,
                'attributes' => $class->latestVersion->attribute_schema ?? [],
            ])->values()->all(),
            'vocabulary' => [
                'geometryTypes' => GeometryType::options(),
                'attributeTypes' => AttributeType::options(),
            ],
        ]);
    }

    public function save(Request $request, ManageFeatureClasses $classes): RedirectResponse
    {
        $data = CampaignCaptureController::validateClass($request);
        $actor = $request->user() instanceof User ? $request->user() : null;

        if (($data['id'] ?? null) !== null) {
            $class = FeatureClass::query()->templates()->findOrFail($data['id']);
            $classes->revise($class, $data, $actor);

            return back()->with('status', "{$class->label} saved.");
        }

        $class = $classes->create(null, $data, $actor);

        return back()->with('status', "{$class->label} added.");
    }
}
