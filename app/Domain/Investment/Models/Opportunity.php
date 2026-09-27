<?php

declare(strict_types=1);

namespace App\Domain\Investment\Models;

use App\Domain\Investment\Enums\Seeking;
use App\Domain\Party\Models\Party;
use App\Domain\Registry\Models\Enterprise;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What a business has chosen to tell investors.
 *
 * @property int $id
 * @property int $enterprise_id
 * @property int $party_id
 * @property Seeking $seeking
 * @property int|null $ticket_size_minor
 * @property string $currency
 * @property string|null $use_of_funds
 * @property string|null $summary
 * @property int|null $operating_since
 * @property string|null $staff_on_site
 * @property string|null $premises
 * @property string $status
 * @property Carbon|null $published_at
 * @property Enterprise $enterprise
 */
final class Opportunity extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'enterprise_id', 'party_id', 'seeking', 'ticket_size_minor', 'currency', 'use_of_funds',
        'summary', 'operating_since', 'staff_on_site', 'premises', 'status', 'published_at', 'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'seeking' => Seeking::class,
            'published_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return HasMany<DataRoomDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(DataRoomDocument::class);
    }

    /** @return HasMany<DataRoomGrant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(DataRoomGrant::class);
    }

    /**
     * The only opportunities an investor ever sees. Asked for by name, like the
     * storefront photographs: published, and by nothing else.
     *
     * @param  Builder<Opportunity>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', self::STATUS_PUBLISHED);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
