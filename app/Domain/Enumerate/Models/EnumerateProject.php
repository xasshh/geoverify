<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Models;

use App\Domain\Campaign\Models\Campaign;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An organisation's commission for custom enumeration. See the migration.
 *
 * @property int $id
 * @property string $reference
 * @property int $organisation_id
 * @property int $requested_by
 * @property string $name
 * @property string $subject
 * @property string $area
 * @property int|null $target_records
 * @property Carbon|null $wanted_by
 * @property list<array{label: string, type: string}> $fields
 * @property string|null $notes
 * @property string $status
 * @property int|null $campaign_id
 * @property Carbon|null $created_at
 * @property-read EnumerateOrganisation|null $organisation
 * @property-read Campaign|null $campaign
 */
final class EnumerateProject extends Model
{
    public const STATUSES = [
        'requested' => 'Requested',
        'scoping' => 'Scoping with your account manager',
        'live' => 'Live',
        'closed' => 'Closed',
        'declined' => 'Declined',
    ];

    public const FIELD_TYPES = ['text', 'number', 'list', 'yes_no', 'photo', 'date'];

    protected $fillable = [
        'reference', 'organisation_id', 'requested_by', 'name', 'subject', 'area',
        'target_records', 'wanted_by', 'fields', 'notes', 'status', 'campaign_id',
    ];

    protected function casts(): array
    {
        return ['fields' => 'array', 'wanted_by' => 'date', 'target_records' => 'integer'];
    }

    /** @return BelongsTo<EnumerateOrganisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(EnumerateOrganisation::class, 'organisation_id');
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
