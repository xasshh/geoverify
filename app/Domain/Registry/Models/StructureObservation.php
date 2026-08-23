<?php

declare(strict_types=1);

namespace App\Domain\Registry\Models;

use App\Domain\Field\Models\Assignment;
use App\Domain\Field\Models\FieldSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a structure looked like on one visit. Append only.
 *
 * @property int $id
 * @property int $structure_id
 * @property int $captured_by
 * @property int|null $field_session_id
 * @property int|null $assignment_id
 * @property Carbon $observed_at
 * @property float|null $capture_accuracy_m
 * @property string $structure_type
 * @property string|null $layout_class
 * @property int|null $floors
 * @property int|null $unit_count
 * @property string|null $condition
 * @property string $occupancy_status
 * @property string|null $notes
 * @property int|null $confidence_score
 * @property string $status
 * @property string $client_uuid
 */
final class StructureObservation extends Model
{
    protected $fillable = [
        'structure_id', 'captured_by', 'field_session_id', 'assignment_id',
        'observed_at', 'capture_accuracy_m', 'structure_type', 'layout_class',
        'floors', 'unit_count', 'condition', 'occupancy_status', 'notes',
        'confidence_score', 'status', 'client_uuid',
    ];

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'capture_accuracy_m' => 'float',
            'floors' => 'integer',
            'unit_count' => 'integer',
            'confidence_score' => 'integer',
        ];
    }

    /** @return BelongsTo<Structure, $this> */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(Structure::class);
    }

    /** @return BelongsTo<User, $this> */
    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    /** @return BelongsTo<FieldSession, $this> */
    public function fieldSession(): BelongsTo
    {
        return $this->belongsTo(FieldSession::class);
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }
}
