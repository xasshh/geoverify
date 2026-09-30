<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One answer from a register, as the provider gave it. Appended, never edited:
 * a lookup run again is a second row.
 *
 * @property int $id
 * @property int $enumerate_request_id
 * @property string $kind
 * @property string $provider
 * @property string $outcome
 * @property array<string, mixed>|null $facts
 * @property string|null $note
 * @property Carbon $checked_at
 * @property-read EnumerateRequest|null $request
 */
final class RegistryCheck extends Model
{
    public const UPDATED_AT = null;

    public const MATCHED = 'matched';

    public const MISMATCHED = 'mismatched';

    public const NOT_FOUND = 'not_found';

    public const UNAVAILABLE = 'unavailable';

    protected $fillable = ['enumerate_request_id', 'kind', 'provider', 'outcome', 'facts', 'note', 'checked_at'];

    protected function casts(): array
    {
        return ['facts' => 'array', 'checked_at' => 'datetime'];
    }

    /** @return BelongsTo<EnumerateRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(EnumerateRequest::class, 'enumerate_request_id');
    }
}
