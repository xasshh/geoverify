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
final class WatchlistEntry extends Model
{
    protected $table = 'investor_watchlist';

    protected $fillable = ['investor_organisation_id', 'opportunity_id', 'added_by', 'removed_at'];

    protected function casts(): array
    {
        return ['removed_at' => 'datetime'];
    }

    /** @return BelongsTo<Opportunity, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }
}
