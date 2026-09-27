<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A line as agreed: name, unit and price copied from the product when the
 * order was placed, so a later edit to the catalogue cannot reach it.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int $product_id
 * @property string $name
 * @property string|null $unit
 * @property int $unit_price_minor
 * @property int $quantity
 * @property int $line_minor
 */
final class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'product_id', 'name', 'unit', 'unit_price_minor', 'quantity', 'line_minor',
    ];

    protected function casts(): array
    {
        return ['unit_price_minor' => 'integer', 'quantity' => 'integer', 'line_minor' => 'integer'];
    }
}
