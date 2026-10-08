<?php

declare(strict_types=1);

namespace App\Domain\AreaCapture\Actions;

use App\Domain\AreaCapture\Exceptions\ImportRefused;
use App\Domain\AreaCapture\Models\AreaFeatureBatch;
use App\Domain\AreaCapture\Models\AreaFeatureRevision;
use App\Domain\Campaign\Models\FeatureClass;
use App\Domain\Coverage\Actions\ReadVectorFile;
use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Features of the land from a file a client sent, in two steps.
 *
 * Preview reads the file and says what is in it (how many shapes of each
 * kind, which properties they carry and the values those take), so the
 * person importing can say which values are which class. Commit then runs
 * every shape through CaptureAreaFeature, the same rules as a hand-drawn one,
 * inside one transaction: if any shape is refused, none is kept, and the
 * person is told which and why. A half-imported file is worse than none.
 */
final class ImportAreaFeatures
{
    private const MAX_FEATURES = 20_000;

    /** How many distinct values of a property are listed for mapping. */
    private const MAX_VALUES = 60;

    public function __construct(
        private readonly ReadVectorFile $files,
        private readonly CaptureAreaFeature $capture,
    ) {}

    /**
     * @return array{batch: AreaFeatureBatch, total: int, geometry: array<string, int>, properties: array<string, list<string>>}
     */
    public function preview(CoverageArea $area, UploadedFile $file, User $actor): array
    {
        $collection = ($this->files)((string) $file->getRealPath(), $file->getClientOriginalExtension());
        $features = $collection['features'];

        if ($features === []) {
            throw ValidationException::withMessages(['file' => 'The file holds no features.']);
        }

        if (count($features) > self::MAX_FEATURES) {
            throw ValidationException::withMessages(['file' => 'More than '.number_format(self::MAX_FEATURES).' features. Split the file.']);
        }

        $geometry = [];
        $properties = [];

        foreach ($features as $feature) {
            $type = is_array($feature['geometry'] ?? null) ? (string) ($feature['geometry']['type'] ?? 'none') : 'none';
            $geometry[$type] = ($geometry[$type] ?? 0) + 1;

            foreach ((array) ($feature['properties'] ?? []) as $key => $value) {
                if (is_scalar($value) && count($properties[$key] ?? []) < self::MAX_VALUES) {
                    $properties[$key][(string) $value] = true;
                }
            }
        }

        $path = 'area-imports/'.Str::lower((string) Str::ulid()).'.geojson';
        Storage::disk('local')->put($path, json_encode($collection, JSON_THROW_ON_ERROR));

        $batch = AreaFeatureBatch::query()->create([
            'coverage_area_id' => $area->id,
            'kind' => AreaFeatureBatch::KIND_IMPORT,
            'status' => 'queued',
            'source_name' => $file->getClientOriginalName(),
            'source_path' => $path,
            'requested_by' => $actor->id,
        ]);

        return [
            'batch' => $batch,
            'total' => count($features),
            'geometry' => $geometry,
            'properties' => array_map(static fn (array $values): array => array_map('strval', array_keys($values)), $properties),
        ];
    }

    /**
     * @param  array{class_id?: int|null, property?: string|null, values?: array<string, int|null>}  $mapping
     *                                                                                                         One class for every shape, or a property whose values name classes.
     *                                                                                                         Shapes whose value maps to nothing are skipped, not refused.
     */
    public function commit(AreaFeatureBatch $batch, array $mapping, User $actor): AreaFeatureBatch
    {
        if ($batch->status !== 'queued' || $batch->kind !== AreaFeatureBatch::KIND_IMPORT || $batch->source_path === null) {
            throw ValidationException::withMessages(['batch' => 'This import has already run.']);
        }

        $area = CoverageArea::query()->findOrFail($batch->coverage_area_id);

        /** @var array{features: list<array<string, mixed>>} $collection */
        $collection = json_decode((string) Storage::disk('local')->get($batch->source_path), true, flags: JSON_THROW_ON_ERROR);

        $classes = FeatureClass::query()
            ->where('campaign_id', $area->campaign_id)
            ->with('latestVersion')
            ->get()
            ->keyBy('id');

        $refusals = [];
        $created = 0;
        $skipped = 0;

        try {
            DB::transaction(function () use ($collection, $mapping, $classes, $area, $batch, $actor, &$refusals, &$created, &$skipped): void {
                foreach ($collection['features'] as $index => $feature) {
                    $classId = $this->classFor($feature, $mapping);

                    if ($classId === null) {
                        $skipped++;

                        continue;
                    }

                    $class = $classes->get($classId);

                    if ($class === null) {
                        $refusals[] = ['index' => $index, 'message' => 'That class is not in this campaign.'];

                        continue;
                    }

                    try {
                        // Each in its own savepoint, so one refusal is recorded
                        // and the rest are still tried, for a full report.
                        DB::transaction(fn () => ($this->capture)([
                            'client_uuid' => (string) Str::uuid7(),
                            'feature_uuid' => (string) Str::uuid7(),
                            'feature_class_id' => $class->id,
                            'class_version' => $class->latestVersion?->version,
                            'capture_method' => AreaFeatureRevision::METHOD_IMPORTED,
                            'coverage_area_id' => $area->id,
                            'geometry' => $feature['geometry'] ?? null,
                            'answers' => [],
                            'area_feature_batch_id' => $batch->id,
                        ], $actor, CaptureAreaFeature::DESK));
                        $created++;
                    } catch (ValidationException $e) {
                        $refusals[] = ['index' => $index, 'message' => (string) collect($e->errors())->flatten()->first()];
                    }
                }

                // All or nothing: a refusal anywhere undoes the lot.
                if ($refusals !== []) {
                    throw new ImportRefused;
                }
            });
        } catch (ImportRefused) {
            $batch->forceFill([
                'status' => 'failed',
                'mapping' => $mapping,
                'created_count' => 0,
                'refused_count' => count($refusals),
                'refusals' => array_slice($refusals, 0, 50),
                'error' => count($refusals).' of the shapes were refused, so nothing was imported.',
                'finished_at' => now(),
            ])->save();

            return $batch;
        } catch (Throwable $e) {
            $batch->forceFill(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 500), 'finished_at' => now()])->save();

            throw $e;
        }

        $batch->forceFill([
            'status' => 'done',
            'mapping' => $mapping,
            'created_count' => $created,
            'refused_count' => 0,
            'error' => $skipped > 0 ? "{$skipped} shapes had no class mapped and were left out." : null,
            'finished_at' => now(),
        ])->save();

        VerificationEvent::record($batch, 'area_features.imported', $actor, [
            'coverage_area_id' => $area->id,
            'created' => $created,
            'skipped' => $skipped,
            'file' => $batch->source_name,
        ]);

        return $batch;
    }

    /**
     * @param  array<string, mixed>  $feature
     * @param  array{class_id?: int|null, property?: string|null, values?: array<string, int|null>}  $mapping
     */
    private function classFor(array $feature, array $mapping): ?int
    {
        if (($mapping['class_id'] ?? null) !== null) {
            return (int) $mapping['class_id'];
        }

        $property = $mapping['property'] ?? null;

        if ($property === null) {
            return null;
        }

        $value = ((array) ($feature['properties'] ?? []))[$property] ?? null;

        if (! is_scalar($value)) {
            return null;
        }

        $classId = ($mapping['values'] ?? [])[(string) $value] ?? null;

        return $classId === null ? null : (int) $classId;
    }
}
