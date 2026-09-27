<?php

declare(strict_types=1);

namespace App\Domain\Investment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $investor_organisation_id
 * @property int $opportunity_id
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class InvestorNote extends Model
{
    protected $table = 'investor_notes';

    protected $fillable = ['investor_organisation_id', 'opportunity_id', 'body', 'updated_by'];

    /** @return BelongsTo<Opportunity, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }
}
