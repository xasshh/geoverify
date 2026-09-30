<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Models;

use App\Domain\Party\Models\PortalAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A support thread, usually about one request. See the migration.
 *
 * @property int $id
 * @property string $reference
 * @property int $portal_account_id
 * @property int|null $enumerate_request_id
 * @property string $category
 * @property string $subject
 * @property string $status
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PortalAccount|null $account
 * @property-read EnumerateRequest|null $request
 */
final class EnumerateTicket extends Model
{
    /** Waiting on the support desk. */
    public const OPEN = 'open';

    /** The desk has answered and is working on it. */
    public const IN_REVIEW = 'in_review';

    public const RESOLVED = 'resolved';

    public const CATEGORIES = [
        'report_quality' => 'Report quality',
        'registry_result' => 'Registry result',
        'payments' => 'Payments',
        'general' => 'General',
    ];

    protected $fillable = ['reference', 'portal_account_id', 'enumerate_request_id', 'category', 'subject', 'status', 'resolved_at'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<PortalAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class, 'portal_account_id');
    }

    /** @return BelongsTo<EnumerateRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(EnumerateRequest::class, 'enumerate_request_id');
    }

    /** @return HasMany<EnumerateTicketMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(EnumerateTicketMessage::class)->orderBy('created_at')->orderBy('id');
    }
}
