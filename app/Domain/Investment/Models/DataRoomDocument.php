<?php

declare(strict_types=1);

namespace App\Domain\Investment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $opportunity_id
 * @property string $title
 * @property string|null $description
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $bytes
 * @property string $status
 * @property Carbon|null $created_at
 */
final class DataRoomDocument extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'opportunity_id', 'title', 'description', 'disk', 'path', 'mime', 'bytes',
        'uploaded_by_party_id', 'uploaded_by_account_id', 'status',
    ];

    /** @return BelongsTo<Opportunity, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }
}
