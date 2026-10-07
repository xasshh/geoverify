<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Actions;

use App\Domain\Campaign\Enums\GeometryType;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\FeatureClass;
use App\Domain\Campaign\Models\FeatureClassVersion;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The feature class catalogue: templates, a campaign's copies, and revisions.
 *
 * Three rules hold here and nowhere else:
 *
 *  - The attribute form is versioned. Changing it writes a new version; the old
 *    one is untouched (the database refuses), so a feature captured against it
 *    keeps its meaning.
 *  - The geometry type is fixed once a class exists. A river that became a
 *    polygon would orphan every line already drawn; that is a new class.
 *  - A campaign never edits a template. It edits its own copy.
 *
 * Nothing is deleted. A class nobody should pick any more is deactivated.
 */
final class ManageFeatureClasses
{
    public function __construct(private readonly NormaliseAttributeSchema $normalise) {}

    /**
     * @param  array{key: string, label: string, geometry_type: string, attributes?: array<int, mixed>, style?: array<string, string>|null, exclusivity_group?: string|null, description?: string|null, sort_order?: int|null}  $data
     */
    public function create(?Campaign $campaign, array $data, ?User $actor): FeatureClass
    {
        $schema = ($this->normalise)($data['attributes'] ?? []);
        $type = GeometryType::tryFrom($data['geometry_type'])
            ?? throw ValidationException::withMessages(['geometry_type' => 'Choose point, line or area.']);

        $exists = FeatureClass::query()
            ->where('key', $data['key'])
            ->when(
                $campaign === null,
                fn ($q) => $q->whereNull('campaign_id'),
                fn ($q) => $q->where('campaign_id', $campaign?->id),
            )
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['key' => "There is already a class called {$data['key']} here."]);
        }

        return DB::transaction(function () use ($campaign, $data, $type, $schema, $actor): FeatureClass {
            $class = FeatureClass::query()->create([
                'campaign_id' => $campaign?->id,
                'key' => $data['key'],
                'label' => $data['label'],
                'geometry_type' => $type,
                'style' => $data['style'] ?? null,
                'exclusivity_group' => $data['exclusivity_group'] ?? null,
                'description' => $data['description'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            $this->writeVersion($class, $schema, $actor);

            VerificationEvent::record($class, 'feature_class.created', $actor, [
                'campaign_id' => $campaign?->id,
                'key' => $class->key,
            ]);

            return $class;
        });
    }

    /**
     * Changes what may change, and versions the form when it changed.
     *
     * @param  array{label?: string, attributes?: array<int, mixed>, style?: array<string, string>|null, exclusivity_group?: string|null, description?: string|null, is_active?: bool, sort_order?: int, geometry_type?: string}  $changes
     */
    public function revise(FeatureClass $class, array $changes, ?User $actor): FeatureClass
    {
        if (isset($changes['geometry_type']) && $changes['geometry_type'] !== $class->geometry_type->value) {
            throw ValidationException::withMessages([
                'geometry_type' => 'A class keeps its shape. Make a new class for a different one.',
            ]);
        }

        return DB::transaction(function () use ($class, $changes, $actor): FeatureClass {
            $presentational = array_intersect_key($changes, array_flip([
                'label', 'style', 'exclusivity_group', 'description', 'is_active', 'sort_order',
            ]));

            $class->fill($presentational);
            $dirty = array_keys($class->getDirty());
            $class->save();

            $newVersion = null;

            if (array_key_exists('attributes', $changes)) {
                $schema = ($this->normalise)($changes['attributes'] ?? []);
                $latest = $class->latestVersion()->first();

                if ($latest === null || self::canonical($latest->attribute_schema) !== self::canonical($schema)) {
                    $newVersion = $this->writeVersion($class, $schema, $actor)->version;
                }
            }

            if ($dirty !== [] || $newVersion !== null) {
                VerificationEvent::record($class, 'feature_class.revised', $actor, array_filter([
                    'changed' => $dirty === [] ? null : $dirty,
                    'version' => $newVersion,
                ], static fn (mixed $v): bool => $v !== null));
            }

            return $class->refresh();
        });
    }

    /**
     * Gives a campaign its own copy of every active template it does not have.
     *
     * Matched on the template it came from, and on key, so running it twice
     * copies nothing the second time and a class the campaign wrote itself
     * under the same key is left alone.
     *
     * @return int the number of classes copied
     */
    public function copyTemplates(Campaign $campaign, ?User $actor): int
    {
        return DB::transaction(function () use ($campaign, $actor): int {
            $have = FeatureClass::query()
                ->where('campaign_id', $campaign->id)
                ->get(['key', 'copied_from_id']);

            $templates = FeatureClass::query()
                ->templates()
                ->where('is_active', true)
                ->with('latestVersion')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->reject(fn (FeatureClass $t): bool => $have->contains('copied_from_id', $t->id)
                    || $have->contains('key', $t->key));

            foreach ($templates as $template) {
                $copy = FeatureClass::query()->create([
                    'campaign_id' => $campaign->id,
                    'key' => $template->key,
                    'label' => $template->label,
                    'geometry_type' => $template->geometry_type,
                    'style' => $template->style,
                    'exclusivity_group' => $template->exclusivity_group,
                    'description' => $template->description,
                    'sort_order' => $template->sort_order,
                    'copied_from_id' => $template->id,
                ]);

                $this->writeVersion($copy, $template->latestVersion->attribute_schema ?? [], $actor);
            }

            if ($templates->isNotEmpty()) {
                VerificationEvent::record($campaign, 'campaign.feature_classes_copied', $actor, [
                    'keys' => $templates->pluck('key')->values()->all(),
                ]);
            }

            return $templates->count();
        });
    }

    /**
     * The form with every attribute's keys sorted.
     *
     * jsonb stores object keys in its own order, so a form read back from the
     * database and the same form freshly normalised differ only in key order.
     * Without this every save would look like a revision.
     *
     * @param  array<int, array<string, mixed>>  $schema
     * @return list<array<string, mixed>>
     */
    private static function canonical(array $schema): array
    {
        return array_map(static function (array $attribute): array {
            ksort($attribute);

            return $attribute;
        }, array_values($schema));
    }

    /**
     * @param  list<array<string, mixed>>  $schema
     */
    private function writeVersion(FeatureClass $class, array $schema, ?User $actor): FeatureClassVersion
    {
        // The class row is locked so two editors saving at once cannot both
        // write version 3. (PostgreSQL will not lock rows under an aggregate.)
        FeatureClass::query()->whereKey($class->id)->lockForUpdate()->first();

        $next = (int) FeatureClassVersion::query()
            ->where('feature_class_id', $class->id)
            ->max('version') + 1;

        return FeatureClassVersion::query()->create([
            'feature_class_id' => $class->id,
            'version' => $next,
            'attribute_schema' => $schema,
            'created_by' => $actor?->id,
        ]);
    }
}
