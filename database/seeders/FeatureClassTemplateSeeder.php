<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Campaign\Actions\ManageFeatureClasses;
use App\Domain\Campaign\Models\FeatureClass;
use Illuminate\Database\Seeder;

/**
 * The global feature class templates a campaign copies from.
 *
 * Safe to run again: a template that exists is revised, which writes a new
 * version only if its form actually changed, and nothing is ever removed. A
 * campaign that already copied a template keeps its own copy untouched.
 *
 * The land cover polygons share one exclusivity group, so a piece of ground is
 * recorded as forest or farmland or water, never two at once. Lines and points
 * belong to no group: a river runs through a forest, and a well sits in a farm.
 */
final class FeatureClassTemplateSeeder extends Seeder
{
    public function run(ManageFeatureClasses $classes): void
    {
        foreach (self::templates() as $order => $template) {
            $existing = FeatureClass::query()->templates()->where('key', $template['key'])->first();

            if ($existing === null) {
                $classes->create(null, $template + ['sort_order' => $order * 10], null);

                continue;
            }

            $classes->revise($existing, [
                'label' => $template['label'],
                'style' => $template['style'],
                'exclusivity_group' => $template['exclusivity_group'],
                'description' => $template['description'],
                'attributes' => $template['attributes'],
            ], null);
        }
    }

    /**
     * @return list<array{key: string, label: string, geometry_type: string, style: array<string, string>, exclusivity_group: string|null, description: string, attributes: list<array<string, mixed>>}>
     */
    public static function templates(): array
    {
        $landCover = 'land_cover';

        $condition = [
            'key' => 'condition', 'label' => 'Condition', 'type' => 'select',
            'options' => ['Good', 'Fair', 'Poor', 'Not usable'], 'required' => false, 'field_only' => true,
        ];

        return [
            [
                'key' => 'forest_woodland', 'label' => 'Forest or woodland', 'geometry_type' => 'polygon',
                'style' => ['fill' => '#2F6B3A', 'stroke' => '#1E4A27'], 'exclusivity_group' => $landCover,
                'description' => 'Land mostly under tree canopy, natural or planted.',
                'attributes' => [
                    ['key' => 'forest_type', 'label' => 'Forest type', 'type' => 'select', 'options' => ['Natural forest', 'Plantation', 'Riparian', 'Woodland savanna'], 'required' => true, 'field_only' => false],
                    ['key' => 'canopy_density', 'label' => 'Canopy density', 'type' => 'select', 'options' => ['Dense (over 70%)', 'Moderate (40 to 70%)', 'Open (10 to 40%)'], 'required' => true, 'field_only' => false],
                    ['key' => 'dominant_species', 'label' => 'Dominant species', 'type' => 'text', 'required' => false, 'field_only' => true],
                    ['key' => 'protected_status', 'label' => 'Protected status', 'type' => 'select', 'options' => ['Forest reserve', 'Community forest', 'None known'], 'required' => false, 'field_only' => true],
                    ['key' => 'disturbance', 'label' => 'Signs of disturbance', 'type' => 'multiselect', 'options' => ['Logging', 'Burning', 'Grazing', 'Clearing for farming', 'None seen'], 'required' => false, 'field_only' => true],
                ],
            ],
            [
                'key' => 'individual_tree', 'label' => 'Individual tree', 'geometry_type' => 'point',
                'style' => ['fill' => '#3E8E4F', 'icon' => 'tree'], 'exclusivity_group' => null,
                'description' => 'A single tree worth recording on its own: a landmark, an economic or protected species.',
                'attributes' => [
                    ['key' => 'species', 'label' => 'Species', 'type' => 'text', 'required' => true, 'field_only' => true],
                    ['key' => 'girth_cm', 'label' => 'Girth at chest height', 'type' => 'number', 'unit' => 'cm', 'required' => false, 'field_only' => true],
                    ['key' => 'use', 'label' => 'Use', 'type' => 'multiselect', 'options' => ['Fruit', 'Timber', 'Shade', 'Medicinal', 'Shrine or sacred', 'Boundary marker'], 'required' => false, 'field_only' => true],
                ],
            ],
            [
                'key' => 'grassland_savanna', 'label' => 'Grassland or savanna', 'geometry_type' => 'polygon',
                'style' => ['fill' => '#C2B45A', 'stroke' => '#8C8132'], 'exclusivity_group' => $landCover,
                'description' => 'Open land under grass or scattered shrubs, not cultivated.',
                'attributes' => [
                    ['key' => 'cover', 'label' => 'Cover', 'type' => 'select', 'options' => ['Open grassland', 'Shrub savanna', 'Tree savanna'], 'required' => true, 'field_only' => false],
                    ['key' => 'grazing', 'label' => 'Used for grazing', 'type' => 'boolean', 'required' => false, 'field_only' => true],
                    ['key' => 'burnt', 'label' => 'Recently burnt', 'type' => 'boolean', 'required' => false, 'field_only' => false],
                ],
            ],
            [
                'key' => 'farmland', 'label' => 'Farmland', 'geometry_type' => 'polygon',
                'style' => ['fill' => '#D9A441', 'stroke' => '#A67722'], 'exclusivity_group' => $landCover,
                'description' => 'Cultivated or fallow farm plots.',
                'attributes' => [
                    ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['Cultivated', 'Fallow', 'Abandoned'], 'required' => true, 'field_only' => false],
                    ['key' => 'main_crop', 'label' => 'Main crop', 'type' => 'select', 'options' => ['Yam', 'Cassava', 'Rice', 'Maize', 'Sorghum', 'Millet', 'Soybean', 'Groundnut', 'Sesame', 'Citrus', 'Mango', 'Cashew', 'Mixed', 'Other'], 'required' => false, 'field_only' => true],
                    ['key' => 'other_crops', 'label' => 'Other crops seen', 'type' => 'text', 'required' => false, 'field_only' => true],
                    ['key' => 'irrigated', 'label' => 'Irrigated', 'type' => 'boolean', 'required' => false, 'field_only' => true],
                    ['key' => 'farm_size_class', 'label' => 'Farm scale', 'type' => 'select', 'options' => ['Smallholder', 'Medium', 'Commercial'], 'required' => false, 'field_only' => true],
                ],
            ],
            [
                'key' => 'river_stream', 'label' => 'River or stream', 'geometry_type' => 'line',
                'style' => ['stroke' => '#2E7FB8'], 'exclusivity_group' => null,
                'description' => 'A watercourse, drawn along its centre line.',
                'attributes' => [
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => false, 'field_only' => false],
                    ['key' => 'flow', 'label' => 'Flow', 'type' => 'select', 'options' => ['Permanent', 'Seasonal', 'Dry when visited'], 'required' => true, 'field_only' => false],
                    ['key' => 'width_m', 'label' => 'Approximate width', 'type' => 'number', 'unit' => 'm', 'required' => false, 'field_only' => true],
                    ['key' => 'crossing', 'label' => 'Crossings seen', 'type' => 'multiselect', 'options' => ['Bridge', 'Culvert', 'Ford', 'Canoe crossing', 'None'], 'required' => false, 'field_only' => true],
                ],
            ],
            [
                'key' => 'water_body', 'label' => 'Lake, pond or reservoir', 'geometry_type' => 'polygon',
                'style' => ['fill' => '#5DA9DD', 'stroke' => '#2E7FB8'], 'exclusivity_group' => $landCover,
                'description' => 'Standing open water.',
                'attributes' => [
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => false, 'field_only' => false],
                    ['key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => ['Natural lake or pond', 'Dam reservoir', 'Fish pond', 'Borrow pit'], 'required' => true, 'field_only' => false],
                    ['key' => 'permanence', 'label' => 'Permanence', 'type' => 'select', 'options' => ['Permanent', 'Seasonal'], 'required' => false, 'field_only' => true],
                ],
            ],
            [
                'key' => 'wetland', 'label' => 'Wetland or swamp', 'geometry_type' => 'polygon',
                'style' => ['fill' => '#6FB7A8', 'stroke' => '#3D8475'], 'exclusivity_group' => $landCover,
                'description' => 'Land waterlogged for part or all of the year: swamp, marsh, floodplain fadama.',
                'attributes' => [
                    ['key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => ['Swamp forest', 'Marsh', 'Floodplain (fadama)', 'Rice field'], 'required' => true, 'field_only' => false],
                    ['key' => 'flooded_when_visited', 'label' => 'Flooded when visited', 'type' => 'boolean', 'required' => false, 'field_only' => true],
                ],
            ],
            [
                'key' => 'water_point', 'label' => 'Water point', 'geometry_type' => 'point',
                'style' => ['fill' => '#1F6FA8', 'icon' => 'water'], 'exclusivity_group' => null,
                'description' => 'A place people or animals draw water.',
                'attributes' => [
                    ['key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => ['Borehole with hand pump', 'Motorised borehole', 'Hand-dug well', 'Spring', 'Stream access point', 'Water tank', 'Other'], 'required' => true, 'field_only' => true],
                    ['key' => 'functional', 'label' => 'Working when visited', 'type' => 'boolean', 'required' => true, 'field_only' => true],
                    ['key' => 'managed_by', 'label' => 'Managed by', 'type' => 'select', 'options' => ['Community', 'Government', 'Private', 'NGO', 'Unknown'], 'required' => false, 'field_only' => true],
                    ['key' => 'users_per_day', 'label' => 'Users per day, estimated', 'type' => 'number', 'required' => false, 'field_only' => true],
                ],
            ],
            [
                'key' => 'road_track_footpath', 'label' => 'Road, track or footpath', 'geometry_type' => 'line',
                'style' => ['stroke' => '#8A6A4A'], 'exclusivity_group' => null,
                'description' => 'Any way people travel on, from a paved road to a bush path.',
                'attributes' => [
                    ['key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => ['Paved road', 'Laterite road', 'Motorable track', 'Motorcycle track', 'Footpath'], 'required' => true, 'field_only' => false],
                    ['key' => 'passable_wet_season', 'label' => 'Passable in the rains', 'type' => 'boolean', 'required' => false, 'field_only' => true],
                    $condition,
                ],
            ],
            [
                'key' => 'bare_land_rock', 'label' => 'Bare land or rock', 'geometry_type' => 'polygon',
                'style' => ['fill' => '#B9A99A', 'stroke' => '#857565'], 'exclusivity_group' => $landCover,
                'description' => 'Exposed soil, sand, rock outcrop, quarry or erosion.',
                'attributes' => [
                    ['key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => ['Rock outcrop', 'Sand bank', 'Quarry or mine', 'Erosion or gully', 'Cleared land'], 'required' => true, 'field_only' => false],
                ],
            ],
            [
                'key' => 'settlement_cluster', 'label' => 'Settlement', 'geometry_type' => 'polygon',
                'style' => ['fill' => '#C97B63', 'stroke' => '#94503B'], 'exclusivity_group' => $landCover,
                'description' => 'A village, hamlet or compound group, drawn around its buildings.',
                'attributes' => [
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => false, 'field_only' => false],
                    ['key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => ['Town', 'Village', 'Hamlet', 'Farm camp', 'IDP camp', 'Herder settlement'], 'required' => true, 'field_only' => false],
                    ['key' => 'households_estimate', 'label' => 'Households, estimated', 'type' => 'number', 'required' => false, 'field_only' => true],
                ],
            ],
            [
                'key' => 'boundary_landmark', 'label' => 'Boundary or landmark', 'geometry_type' => 'point',
                'style' => ['fill' => '#6B4E9B', 'icon' => 'flag'], 'exclusivity_group' => null,
                'description' => 'A boundary pillar, beacon, sacred site or named landmark.',
                'attributes' => [
                    ['key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => ['Survey beacon', 'Boundary pillar', 'Shrine or sacred site', 'Named landmark', 'Other'], 'required' => true, 'field_only' => true],
                    ['key' => 'name', 'label' => 'Name or inscription', 'type' => 'text', 'required' => false, 'field_only' => true],
                ],
            ],
            [
                'key' => 'infrastructure', 'label' => 'Infrastructure', 'geometry_type' => 'point',
                'style' => ['fill' => '#4A5560', 'icon' => 'tower'], 'exclusivity_group' => null,
                'description' => 'A facility outside the building register: a mast, transformer, bridge, market or school.',
                'attributes' => [
                    ['key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => ['Telecom mast', 'Transformer or power line', 'Bridge', 'Culvert', 'Market', 'School', 'Health post', 'Other'], 'required' => true, 'field_only' => false],
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => false, 'field_only' => true],
                    $condition,
                ],
            ],
        ];
    }
}
