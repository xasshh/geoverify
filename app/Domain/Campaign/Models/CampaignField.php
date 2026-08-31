<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Models;

use App\Domain\Campaign\Enums\CampaignFieldType;
use Database\Factories\Domain\Campaign\Models\CampaignFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One declared item in a campaign's data schema.
 *
 * @property int $id
 * @property int $campaign_id
 * @property string $label
 * @property string $key
 * @property CampaignFieldType $type
 * @property array<int, string>|null $options
 * @property bool $is_required
 * @property int $sort_order
 * @property string|null $help_text
 */
final class CampaignField extends Model
{
    /** @use HasFactory<CampaignFieldFactory> */
    use HasFactory;

    protected $fillable = [
        'campaign_id', 'label', 'key', 'type', 'options',
        'is_required', 'sort_order', 'help_text',
    ];

    protected function casts(): array
    {
        return [
            'type' => CampaignFieldType::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
