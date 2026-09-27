<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Models;

use App\Domain\Media\Models\Media;
use App\Domain\Registry\Models\Enterprise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Something a business sells, as the business describes it.
 *
 * @property int $id
 * @property int $enterprise_id
 * @property int $party_id
 * @property string $name
 * @property string|null $unit
 * @property int|null $price_minor
 * @property string $currency
 * @property string|null $description
 * @property string $status
 * @property int $position
 */
final class Product extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_HIDDEN = 'hidden';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const MAX_PHOTOS = 4;

    protected $fillable = [
        'enterprise_id', 'party_id', 'name', 'unit', 'price_minor', 'currency', 'description', 'status', 'position',
    ];

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'position' => 'integer'];
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /**
     * The business's own photographs of this product, and only those: asked
     * for by kind and by a party author, never "everything but evidence".
     *
     * @return MorphMany<Media, $this>
     */
    public function photos(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')
            ->where('kind', Media::KIND_PRODUCT)
            ->whereNotNull('uploaded_by_party_id')
            ->where('status', Media::STATUS_STORED)
            ->orderBy('id');
    }
}
