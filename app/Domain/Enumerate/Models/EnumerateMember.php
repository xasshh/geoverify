<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Models;

use App\Domain\Party\Models\PortalAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A seat in an organisation, and what it may do.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string|null $phone
 * @property string|null $email
 * @property int|null $portal_account_id
 * @property string $role
 * @property int|null $invited_by
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property-read EnumerateOrganisation|null $organisation
 * @property-read PortalAccount|null $account
 */
final class EnumerateMember extends Model
{
    protected $table = 'enumerate_organisation_members';

    public const ROLES = [
        'admin' => 'Admin',
        'project_lead' => 'Project lead',
        'requester' => 'Requester',
        'viewer' => 'Viewer',
    ];

    /**
     * What each role may do. Viewing is every role's; the rest widens down
     * the list: a requester runs checks, a project lead also commissions
     * projects, an admin also manages the team and the money.
     */
    private const ABILITIES = [
        'request' => ['admin', 'project_lead', 'requester'],
        'bulk' => ['admin', 'project_lead', 'requester'],
        'project' => ['admin', 'project_lead'],
        'fund' => ['admin', 'project_lead'],
        'team' => ['admin'],
    ];

    protected $fillable = ['organisation_id', 'phone', 'email', 'portal_account_id', 'role', 'invited_by', 'accepted_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @param  'request'|'bulk'|'project'|'fund'|'team'  $ability */
    public function may(string $ability): bool
    {
        return $this->revoked_at === null
            && $this->accepted_at !== null
            && in_array($this->role, self::ABILITIES[$ability], true);
    }

    /** @return BelongsTo<EnumerateOrganisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(EnumerateOrganisation::class, 'organisation_id');
    }

    /** @return BelongsTo<PortalAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class, 'portal_account_id');
    }
}
