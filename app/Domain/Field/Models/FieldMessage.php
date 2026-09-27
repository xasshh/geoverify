<?php

declare(strict_types=1);

namespace App\Domain\Field\Models;

use App\Domain\Registry\Models\StructureObservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message in an officer's thread. See the migration for the shape.
 *
 * @property int $id
 * @property string $client_uuid
 * @property int $officer_id
 * @property int $sender_id
 * @property string $direction
 * @property string $kind
 * @property string $body
 * @property int|null $observation_id
 * @property int|null $assignment_id
 * @property string|null $broadcast_uuid
 * @property Carbon|null $pinned_at
 * @property Carbon|null $read_at
 * @property Carbon $sent_at
 * @property-read User|null $sender
 * @property-read StructureObservation|null $observation
 */
final class FieldMessage extends Model
{
    public const TO_OFFICER = 'to_officer';

    public const FROM_OFFICER = 'from_officer';

    public const KIND_TEXT = 'text';

    public const KIND_RETURNED = 'returned_record';

    public const KIND_CELL_ASSIGNED = 'cell_assigned';

    public const KIND_BROADCAST = 'broadcast';

    protected $fillable = [
        'client_uuid', 'officer_id', 'sender_id', 'direction', 'kind', 'body',
        'observation_id', 'assignment_id', 'broadcast_uuid', 'pinned_at', 'read_at', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['pinned_at' => 'datetime', 'read_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** @return BelongsTo<StructureObservation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(StructureObservation::class);
    }
}
