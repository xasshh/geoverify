<?php

declare(strict_types=1);

namespace App\Domain\Verification\Models;

use App\Domain\Registry\Models\StructureObservation;
use App\Domain\Verification\Enums\ReviewQuestion;
use App\Domain\Verification\Enums\Verdict;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signal's reading of one capture.
 *
 * @property int $id
 * @property int $structure_observation_id
 * @property string $signal
 * @property ReviewQuestion $question
 * @property Verdict $verdict
 * @property int $weight
 * @property int $deduction
 * @property string $message
 * @property array<string, mixed>|null $evidence
 */
final class ObservationSignal extends Model
{
    protected $fillable = [
        'structure_observation_id', 'signal', 'question', 'verdict',
        'weight', 'deduction', 'message', 'evidence',
    ];

    protected function casts(): array
    {
        return [
            'question' => ReviewQuestion::class,
            'verdict' => Verdict::class,
            'weight' => 'integer',
            'deduction' => 'integer',
            'evidence' => 'array',
        ];
    }

    /** @return BelongsTo<StructureObservation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(StructureObservation::class, 'structure_observation_id');
    }

    /**
     * The readings worth putting in front of a supervisor.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeFlagged(Builder $query): Builder
    {
        return $query->whereIn('verdict', [Verdict::Warn->value, Verdict::Fail->value]);
    }
}
