<?php

declare(strict_types=1);

namespace App\Domain\Verification\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The append only audit log. This log is the product.
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $event
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string|null $actor_label
 * @property array<string, mixed>|null $evidence
 * @property Carbon $occurred_at
 */
final class VerificationEvent extends Model
{
    public const ACTOR_USER = 'user';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_EXTERNAL = 'external';

    public const UPDATED_AT = null;

    protected $fillable = [
        'subject_type', 'subject_id', 'event', 'actor_type', 'actor_id',
        'actor_label', 'evidence', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'occurred_at' => 'datetime'];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Records something that happened, with what it was based on.
     *
     * @param  array<string, mixed>  $evidence
     */
    public static function record(
        Model $subject,
        string $event,
        ?User $actor = null,
        array $evidence = [],
        string $actorType = self::ACTOR_USER,
    ): self {
        return self::query()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'event' => $event,
            'actor_type' => $actor instanceof User ? self::ACTOR_USER : $actorType,
            'actor_id' => $actor?->id,
            'actor_label' => $actor?->name,
            'evidence' => $evidence === [] ? null : $evidence,
            'occurred_at' => now(),
        ]);
    }

    /**
     * The database blocks updates and deletes with a trigger. This stops the
     * attempt earlier, with a message that says why rather than a driver error.
     */
    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException('verification_events is append only. Record a new event instead.');
        });

        self::deleting(function (): never {
            throw new RuntimeException('verification_events is append only. Nothing here is ever removed.');
        });
    }
}
