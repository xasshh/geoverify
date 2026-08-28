<?php

declare(strict_types=1);

namespace App\Domain\Registry\Models;

use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PortalAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A registration in progress.
 *
 * @property int $id
 * @property int $party_id
 * @property int $portal_account_id
 * @property string $step
 * @property array<string, mixed> $payload
 * @property Carbon|null $completed_at
 * @property int|null $enterprise_id
 */
final class BusinessRegistrationDraft extends Model
{
    public const STEP_NAME = 'name';

    public const STEP_PLACE = 'place';

    public const STEP_CONFIRM = 'confirm';

    protected $fillable = ['party_id', 'portal_account_id', 'step', 'payload', 'completed_at', 'enterprise_id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<PortalAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class, 'portal_account_id');
    }

    /**
     * Merge answers in, leaving the rest alone.
     *
     * Merged rather than assigned because each step posts only its own fields,
     * and a person who goes back to change the name must not lose the location
     * they already established.
     *
     * @param  array<string, mixed>  $answers
     */
    public function remember(array $answers): void
    {
        $this->payload = [...$this->payload, ...$answers];
    }

    /** @return mixed */
    public function answer(string $key, mixed $fallback = null)
    {
        return $this->payload[$key] ?? $fallback;
    }

    /**
     * Whether a location has been established.
     *
     * The one thing that cannot be skipped: a business with no location has
     * nothing anybody could go and verify, and so cannot be listed at all.
     */
    public function hasPlace(): bool
    {
        return is_numeric($this->answer('latitude')) && is_numeric($this->answer('longitude'));
    }
}
