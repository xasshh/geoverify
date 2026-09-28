<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How to deal with a business, in its owner's words: hours, delivery, address.
 * Shown only on a claimed, published listing. See the migration.
 *
 * @property int $id
 * @property int $enterprise_id
 * @property int $party_id
 * @property array<string, array{opens: string, closes: string}|null>|null $weekly_hours
 * @property bool|null $delivers
 * @property string|null $street_address
 */
final class BusinessProfile extends Model
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    protected $fillable = ['enterprise_id', 'party_id', 'weekly_hours', 'delivers', 'street_address', 'updated_by'];

    protected function casts(): array
    {
        return ['weekly_hours' => 'array', 'delivers' => 'boolean'];
    }
}
