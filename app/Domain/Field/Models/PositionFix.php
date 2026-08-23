<?php

declare(strict_types=1);

namespace App\Domain\Field\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded position, exactly as the receiver reported it.
 *
 * @property int $id
 * @property int $field_session_id
 * @property Carbon $recorded_at
 * @property float|null $accuracy_m
 * @property float|null $altitude_m
 * @property float|null $speed_mps
 * @property float|null $heading
 * @property int|null $satellite_count
 * @property float|null $hdop
 * @property bool $is_mock
 * @property string|null $provider
 * @property string $source
 */
final class PositionFix extends Model
{
    protected $fillable = [
        'field_session_id', 'recorded_at', 'accuracy_m', 'altitude_m',
        'speed_mps', 'heading', 'satellite_count', 'hdop', 'is_mock',
        'provider', 'source',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'accuracy_m' => 'float',
            'altitude_m' => 'float',
            'speed_mps' => 'float',
            'heading' => 'float',
            'hdop' => 'float',
            'satellite_count' => 'integer',
            'is_mock' => 'boolean',
        ];
    }

    /** @return BelongsTo<FieldSession, $this> */
    public function fieldSession(): BelongsTo
    {
        return $this->belongsTo(FieldSession::class);
    }
}
