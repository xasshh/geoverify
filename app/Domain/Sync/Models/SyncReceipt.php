<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The record of what the server has already accepted.
 *
 * @property int $id
 * @property string $client_uuid
 * @property string $payload_hash
 * @property int $user_id
 * @property string|null $device_id
 * @property string $entity
 * @property string $operation
 * @property string|null $resulting_type
 * @property int|null $resulting_id
 * @property string $status
 * @property string|null $error
 * @property Carbon $received_at
 */
final class SyncReceipt extends Model
{
    protected $fillable = [
        'client_uuid', 'payload_hash', 'user_id', 'device_id', 'entity',
        'operation', 'resulting_type', 'resulting_id', 'status', 'error', 'received_at',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
