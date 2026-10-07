<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One frozen revision of a class's attribute form.
 *
 * The database refuses to update or delete a row here, so a feature captured
 * against version 2 keeps meaning what version 2 asked, whatever the class
 * says today.
 *
 * @phpstan-type Attribute array{key: string, label: string, type: string, options?: list<string>, required: bool, help_text?: string|null, unit?: string|null, field_only: bool}
 *
 * @property int $id
 * @property int $feature_class_id
 * @property int $version
 * @property list<Attribute> $attribute_schema
 * @property int|null $created_by
 * @property Carbon $created_at
 */
final class FeatureClassVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['feature_class_id', 'version', 'attribute_schema', 'created_by'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'attribute_schema' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FeatureClass, $this> */
    public function featureClass(): BelongsTo
    {
        return $this->belongsTo(FeatureClass::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
