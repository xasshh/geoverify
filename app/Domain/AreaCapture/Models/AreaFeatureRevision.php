<?php

declare(strict_types=1);

namespace App\Domain\AreaCapture\Models;

use App\Domain\Campaign\Models\FeatureClassVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One drawing of a feature, by one person, at one time. Append only: the
 * database refuses to change anything but the score written after it.
 *
 * The geometry is read and written in SQL (ST_GeomFromGeoJSON, ST_AsGeoJSON),
 * never through the model, so it carries no cast.
 *
 * @property int $id
 * @property string $client_uuid
 * @property int $area_feature_id
 * @property int $feature_class_version_id
 * @property array<string, mixed> $answers the class version's questions, answered
 * @property string $capture_method
 * @property int|null $basemap_layer_id
 * @property Carbon|null $imagery_date
 * @property string|null $gps_accuracy_m
 * @property int|null $device_id
 * @property int|null $field_session_id
 * @property int|null $assignment_id
 * @property int $captured_by
 * @property Carbon $captured_at
 * @property string|null $area_ha
 * @property string|null $length_m
 * @property bool $geometry_repaired
 * @property string|null $repair_area_change_pct
 * @property int|null $confidence_score
 * @property string|null $notes
 * @property-read AreaFeature $feature
 */
final class AreaFeatureRevision extends Model
{
    public const METHOD_DESK = 'desk_digitised';

    public const METHOD_WALKED = 'field_walked';

    public const METHOD_DRAWN = 'field_drawn';

    public const METHOD_IMPORTED = 'imported';

    public const METHOD_VERIFIED = 'field_verified';

    /** Captured with someone standing there, rather than from imagery or a file. */
    public const FIELD_METHODS = [self::METHOD_WALKED, self::METHOD_DRAWN, self::METHOD_VERIFIED];

    protected $fillable = [
        'client_uuid', 'area_feature_id', 'feature_class_version_id', 'answers', 'capture_method',
        'basemap_layer_id', 'imagery_date', 'gps_accuracy_m', 'device_id', 'field_session_id',
        'assignment_id', 'captured_by', 'captured_at', 'area_ha', 'length_m', 'geometry_repaired',
        'repair_area_change_pct', 'confidence_score', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'imagery_date' => 'date',
            'captured_at' => 'datetime',
            'geometry_repaired' => 'boolean',
            'confidence_score' => 'integer',
        ];
    }

    /** @return BelongsTo<AreaFeature, $this> */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(AreaFeature::class, 'area_feature_id');
    }

    /** @return BelongsTo<FeatureClassVersion, $this> */
    public function classVersion(): BelongsTo
    {
        return $this->belongsTo(FeatureClassVersion::class, 'feature_class_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    public function isFromTheField(): bool
    {
        return in_array($this->capture_method, self::FIELD_METHODS, true);
    }
}
