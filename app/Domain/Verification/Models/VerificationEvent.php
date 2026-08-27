<?php

declare(strict_types=1);

namespace App\Domain\Verification\Models;

use App\Domain\Party\Models\Party;
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

    /**
     * A party acting for itself in the portal.
     *
     * Distinct from `external`, which is a third party system we called. "A
     * shop owner did this" and "the CAC API said this" are different claims and
     * an audit log that flattened them would be answering the wrong question.
     */
    public const ACTOR_PARTY = 'party';

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
     * The same, when the actor is a party rather than a member of staff.
     *
     * A sibling of record() rather than a widened signature. The field platform
     * calls record() from dozens of places against a User, and loosening that
     * parameter to accept either kind would put the burden of telling them
     * apart on every one of those call sites, forever, to serve a caller they
     * do not know about.
     *
     * The party's id and code go in the actor columns because an audit row that
     * says only "a party did this" cannot answer the question an audit log
     * exists to answer. The label is the code, never the display name: names
     * are edited, codes are not, and the row has to still mean something in
     * three years.
     *
     * @param  array<string, mixed>  $evidence
     */
    public static function recordForParty(
        Model $subject,
        string $event,
        Party $party,
        array $evidence = [],
    ): self {
        return self::query()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'event' => $event,
            'actor_type' => self::ACTOR_PARTY,
            'actor_id' => $party->id,
            'actor_label' => $party->code,
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
