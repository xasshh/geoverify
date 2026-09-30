<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a bulk CSV and what became of it.
 *
 * @property int $id
 * @property int $enumerate_batch_id
 * @property int $line
 * @property string|null $name
 * @property string|null $rc_number
 * @property string|null $tin
 * @property string|null $address
 * @property string $outcome
 * @property string|null $reason
 * @property int|null $enumerate_request_id
 * @property-read EnumerateRequest|null $request
 */
final class EnumerateBatchRow extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['enumerate_batch_id', 'line', 'name', 'rc_number', 'tin', 'address', 'outcome', 'reason', 'enumerate_request_id'];

    /** @return BelongsTo<EnumerateRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(EnumerateRequest::class, 'enumerate_request_id');
    }
}
