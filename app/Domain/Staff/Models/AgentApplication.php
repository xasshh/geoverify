<?php

declare(strict_types=1);

namespace App\Domain\Staff\Models;

use App\Domain\Identity\Models\IdentityClaim;
use App\Domain\Party\Models\PortalAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Somebody asking to become a field agent.
 *
 * Held against a portal account, never `users`: an applicant is a member of
 * the public until an administrator approves them, and approving is what makes
 * the staff account (DecideAgentApplication, through CreateStaffMember).
 *
 * @property int $id
 * @property string $reference
 * @property int $portal_account_id
 * @property string $status
 * @property string|null $surname
 * @property string|null $first_name
 * @property string|null $middle_name
 * @property Carbon|null $date_of_birth
 * @property string|null $sex
 * @property string|null $phone
 * @property string|null $whatsapp
 * @property string|null $residential_address
 * @property int|null $residence_state_id
 * @property int|null $residence_lga_id
 * @property int|null $origin_state_id
 * @property int|null $origin_lga_id
 * @property list<string> $languages
 * @property string|null $qualification
 * @property list<string> $experience
 * @property int|null $experience_years
 * @property array<string, string>|null $guarantor
 * @property array<string, string>|null $next_of_kin
 * @property array<string, mixed>|null $device
 * @property array<string, string> $consents
 * @property string|null $signed_name
 * @property Carbon|null $submitted_at
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property list<string> $capture_modes
 * @property int|null $assessment_score
 * @property int|null $user_id
 * @property-read PortalAccount|null $account
 * @property-read User|null $officer
 */
final class AgentApplication extends Model
{
    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const CHANGES_REQUESTED = 'changes_requested';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** The languages the onboarding brief names, plus the ones a Benue team needs. */
    public const LANGUAGES = ['English', 'Pidgin', 'Hausa', 'Yoruba', 'Igbo', 'Tiv', 'Idoma', 'Igede', 'Fulfulde', 'Kanuri', 'Ibibio', 'Edo'];

    public const QUALIFICATIONS = [
        'ssce' => 'SSCE / WAEC / NECO',
        'ond' => 'OND or NCE',
        'hnd' => 'HND',
        'degree' => 'University degree',
        'postgraduate' => 'Postgraduate degree',
    ];

    public const EXPERIENCE = [
        'npc' => 'Population census (NPC)',
        'inec' => 'Elections (INEC)',
        'nbs' => 'Surveys for NBS',
        'ngo' => 'NGO or donor surveys',
        'private' => 'Private research or data collection',
    ];

    public const CAPTURE_MODES = ['buildings', 'points', 'lines', 'areas'];

    /** Each agreement, in the order the form asks for it. */
    public const CONSENTS = [
        'data_processing' => 'I agree that GeoVerify may keep and use the information in this application to assess me and, if I am engaged, to manage my work, under the Nigeria Data Protection Act 2023.',
        'location_tracking' => 'I agree that, once I am working, the app may record my location during my working hours only, and never outside them.',
        'code_of_conduct' => 'I have read and will follow the GeoVerify code of conduct: I will be honest about what I see, polite to every business I visit, and I will never invent a record.',
        'confidentiality' => 'I will keep what I learn about businesses and people private, and will not share photographs or details outside the app.',
        'field_safety' => 'I understand the field safety rules: I will not enter anywhere I feel unsafe, and I will tell my supervisor if something goes wrong.',
    ];

    public const FILE_KINDS = [
        'passport_photo' => 'Passport photograph',
        'government_id' => 'Government ID',
        'qualification_certificate' => 'Certificate for your highest qualification',
        'guarantor_id' => "Your guarantor's ID",
        'guarantor_form' => 'Signed guarantor form',
        'police_certificate' => 'Police character certificate',
    ];

    public const GOVERNMENT_IDS = ['NIN slip', "Voter's card", "Driver's licence", 'International passport'];

    protected $fillable = [
        'reference', 'portal_account_id', 'status',
        'surname', 'first_name', 'middle_name', 'date_of_birth', 'sex', 'phone', 'whatsapp',
        'residential_address', 'residence_state_id', 'residence_lga_id', 'origin_state_id', 'origin_lga_id', 'languages',
        'qualification', 'experience', 'experience_years', 'guarantor', 'next_of_kin',
        'device', 'consents', 'signed_name',
        'submitted_at', 'decided_by', 'decided_at', 'decision_note', 'capture_modes', 'assessment_score', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'languages' => 'array',
            'experience' => 'array',
            // Somebody else's personal data: encrypted at rest.
            'guarantor' => 'encrypted:array',
            'next_of_kin' => 'encrypted:array',
            'device' => 'array',
            'consents' => 'array',
            'capture_modes' => 'array',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** True while the applicant may still change it. */
    public function editable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::CHANGES_REQUESTED], true);
    }

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([$this->first_name, $this->middle_name, $this->surname])));
    }

    /** @return BelongsTo<PortalAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class, 'portal_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<AgentApplicationFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(AgentApplicationFile::class);
    }

    /** @return HasMany<AgentApplicationFile, $this> */
    public function currentFiles(): HasMany
    {
        return $this->files()->whereNull('replaced_at');
    }

    /** @return MorphMany<IdentityClaim, $this> */
    public function identityClaims(): MorphMany
    {
        return $this->morphMany(IdentityClaim::class, 'claimable');
    }
}
