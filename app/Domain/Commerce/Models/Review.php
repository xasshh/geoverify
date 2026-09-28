<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A buyer's rating of an order that was released. See the migration.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int $enterprise_id
 * @property int $buyer_account_id
 * @property int $rating
 * @property string|null $body
 * @property string $status
 * @property Carbon|null $moderated_at
 * @property string|null $moderation_note
 * @property Carbon|null $created_at
 */
final class Review extends Model
{
    public const PUBLISHED = 'published';

    public const HIDDEN = 'hidden';

    protected $fillable = [
        'purchase_order_id', 'enterprise_id', 'buyer_account_id', 'rating', 'body',
        'status', 'moderated_by', 'moderated_at', 'moderation_note',
    ];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'moderated_at' => 'datetime'];
    }
}
