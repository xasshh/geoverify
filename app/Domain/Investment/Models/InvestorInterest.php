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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class InvestorInterest extends Model
{
    protected $table = 'investor_interests';

    protected $fillable = ['investor_organisation_id', 'opportunity_id', 'expressed_by', 'message'];

    /** @return BelongsTo<Opportunity, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }
}
