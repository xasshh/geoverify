<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Enumerate\Actions\MintReference;
use App\Domain\Identity\Actions\HashIdentityReference;
use App\Domain\Identity\Models\IdentityClaim;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Staff\Models\AgentApplication;
use App\Domain\Staff\Models\AgentApplicationFile;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

/**
 * An applicant filling in their application to become a field agent.
 *
 * Four parts, saved one at a time so the form can be finished over several
 * sittings on a phone: about you, background checks, the phone they will work
 * on, and the agreements. Nothing is reviewed until it is submitted, and
 * nothing can be changed after that unless an administrator sends it back.
 *
 * Every write here acts on the applicant's own application. Whose it is comes
 * from the session, never the request.
 */
final class ManageAgentApplication
{
    /** Documents every application needs before it can be sent. */
    public const REQUIRED_FILES = ['passport_photo', 'government_id', 'qualification_certificate', 'guarantor_id', 'guarantor_form'];

    public function __construct(
        private readonly HashIdentityReference $hasher,
        private readonly MintReference $mint,
    ) {}

    public function forAccount(PortalAccount $account): ?AgentApplication
    {
        return AgentApplication::query()->where('portal_account_id', $account->id)->first();
    }

    public function start(PortalAccount $account): AgentApplication
    {
        $existing = $this->forAccount($account);

        if ($existing !== null) {
            return $existing;
        }

        $application = AgentApplication::query()->create([
            'reference' => ($this->mint)('AGT', 4),
            'portal_account_id' => $account->id,
            'status' => AgentApplication::DRAFT,
        ]);

        VerificationEvent::recordForBuyer($application, 'agent_application.started', $account);

        return $application;
    }

    /**
     * Part 1: who they are and where they live.
     *
     * @param  array{surname: string, first_name: string, middle_name?: string|null, date_of_birth: string, sex: string, phone: string, whatsapp?: string|null, residential_address: string, residence_state_id: int, residence_lga_id: int, origin_state_id?: int|null, origin_lga_id?: int|null, languages: list<string>}  $data
     */
    public function saveAboutYou(AgentApplication $application, array $data): void
    {
        $this->mustBeEditable($application);
        $this->mustBeWithin($data['residence_lga_id'], $data['residence_state_id'], 'Choose the local government you live in from the state you chose.');

        if (($data['origin_lga_id'] ?? null) !== null) {
            $this->mustBeWithin((int) $data['origin_lga_id'], (int) ($data['origin_state_id'] ?? 0), 'Choose your local government of origin from your state of origin.');
        }

        $application->fill([
            'surname' => trim($data['surname']),
            'first_name' => trim($data['first_name']),
            'middle_name' => self::blankToNull($data['middle_name'] ?? null),
            'date_of_birth' => $data['date_of_birth'],
            'sex' => $data['sex'],
            'phone' => trim($data['phone']),
            'whatsapp' => self::blankToNull($data['whatsapp'] ?? null),
            'residential_address' => trim($data['residential_address']),
            'residence_state_id' => $data['residence_state_id'],
            'residence_lga_id' => $data['residence_lga_id'],
            'origin_state_id' => $data['origin_state_id'] ?? null,
            'origin_lga_id' => $data['origin_lga_id'] ?? null,
            'languages' => array_values(array_intersect(AgentApplication::LANGUAGES, $data['languages'])),
        ])->save();
    }

    /**
     * Part 2: education, experience, a guarantor and somebody to call.
     *
     * @param  array{qualification: string, experience?: list<string>, experience_years?: int|null, guarantor: array<string, string>, next_of_kin: array<string, string>}  $data
     */
    public function saveBackground(AgentApplication $application, array $data): void
    {
        $this->mustBeEditable($application);

        $application->fill([
            'qualification' => $data['qualification'],
            'experience' => array_values(array_intersect(array_keys(AgentApplication::EXPERIENCE), $data['experience'] ?? [])),
            'experience_years' => $data['experience_years'] ?? null,
            'guarantor' => array_map(trim(...), $data['guarantor']),
            'next_of_kin' => array_map(trim(...), $data['next_of_kin']),
        ])->save();
    }

    /**
     * A NIN or a BVN, kept as a keyed hash and its last four digits. The number
     * itself goes no further than this call.
     */
    public function declareIdentity(AgentApplication $application, string $kind, #[SensitiveParameter] string $number): void
    {
        $this->mustBeEditable($application);

        if (! in_array($kind, IdentityClaim::PERSONAL_KINDS, true)) {
            throw new RuntimeException('Only a NIN or a BVN is asked for here.');
        }

        $hashed = $this->hasher->hash($kind, $number);

        DB::transaction(function () use ($application, $kind, $hashed): void {
            $application->identityClaims()->create([
                'kind' => $kind,
                'reference_token' => $hashed['token'],
                'reference_last4' => $hashed['last4'],
                'status' => IdentityClaim::STATUS_DECLARED,
                'source' => 'agent_application',
                'client_uuid' => (string) Str::uuid(),
            ]);

            $account = $application->account;
            if ($account !== null) {
                VerificationEvent::recordForBuyer($application, 'agent_application.identity_declared', $account, [
                    'kind' => $kind,
                    'last4' => $hashed['last4'],
                ]);
            }
        });
    }

    /**
     * Part 3: the phone, as its own browser described it, and what the
     * applicant told us about it.
     *
     * @param  array<string, mixed>  $device
     */
    public function saveDevice(AgentApplication $application, array $device): void
    {
        $this->mustBeEditable($application);

        $application->forceFill(['device' => $device + ['reported_at' => now()->toIso8601String()]])->save();
    }

    /**
     * A document, encrypted before it is written. The one it replaces is kept
     * and marked replaced.
     */
    public function storeFile(AgentApplication $application, string $kind, UploadedFile $file, ?string $label = null): AgentApplicationFile
    {
        $this->mustBeEditable($application);

        if (! array_key_exists($kind, AgentApplication::FILE_KINDS)) {
            throw new RuntimeException('That is not a document this application asks for.');
        }

        $path = sprintf('agent-applications/%d/%s-%s.enc', $application->id, $kind, Str::random(16));
        Storage::disk('local')->put($path, Crypt::encryptString((string) $file->get()));

        return DB::transaction(function () use ($application, $kind, $file, $label, $path): AgentApplicationFile {
            $application->files()->where('kind', $kind)->whereNull('replaced_at')->update(['replaced_at' => now()]);

            return $application->files()->create([
                'kind' => $kind,
                'label' => $label,
                'path' => $path,
                'mime' => (string) $file->getMimeType(),
                'size_bytes' => (int) $file->getSize(),
            ]);
        });
    }

    /**
     * Part 4: every agreement, each stamped with the moment it was given, and
     * the name they signed with.
     *
     * @param  list<string>  $agreed
     */
    public function agree(AgentApplication $application, array $agreed, string $signedName): void
    {
        $this->mustBeEditable($application);

        $missing = array_diff(array_keys(AgentApplication::CONSENTS), $agreed);
        if ($missing !== []) {
            throw new RuntimeException('Tick every box to agree. You can ask us about any of them first.');
        }

        $stamp = now()->toIso8601String();
        $application->forceFill([
            'consents' => array_fill_keys(array_keys(AgentApplication::CONSENTS), $stamp),
            'signed_name' => trim($signedName),
        ])->save();
    }

    public function submit(AgentApplication $application): void
    {
        $this->mustBeEditable($application);

        if ($this->missing($application) !== []) {
            throw new RuntimeException('Some parts are not finished yet. Each part with something missing is marked.');
        }

        $application->forceFill(['status' => AgentApplication::SUBMITTED, 'submitted_at' => now()])->save();

        $account = $application->account;
        if ($account !== null) {
            VerificationEvent::recordForBuyer($application, 'agent_application.submitted', $account);
        }
    }

    /**
     * What still stands between this application and submitting it, by part.
     *
     * @return array<string, list<string>>
     */
    public function missing(AgentApplication $application): array
    {
        $missing = [];

        $about = [];
        foreach (['surname' => 'Surname', 'first_name' => 'First name', 'date_of_birth' => 'Date of birth', 'sex' => 'Sex', 'phone' => 'Phone number', 'residential_address' => 'Home address', 'residence_lga_id' => 'State and local government you live in'] as $column => $label) {
            if ($application->getAttribute($column) === null) {
                $about[] = $label;
            }
        }
        if ($application->languages === []) {
            $about[] = 'Languages you speak';
        }
        $files = $application->currentFiles()->pluck('kind')->all();
        if (! in_array('passport_photo', $files, true)) {
            $about[] = 'Passport photograph';
        }
        if ($about !== []) {
            $missing['about'] = $about;
        }

        $background = [];
        if (! $application->identityClaims()->where('kind', IdentityClaim::KIND_NIN)->exists()) {
            $background[] = 'NIN';
        }
        if ($application->qualification === null) {
            $background[] = 'Highest qualification';
        }
        if ($application->guarantor === null) {
            $background[] = 'Guarantor details';
        }
        if ($application->next_of_kin === null) {
            $background[] = 'Next of kin';
        }
        foreach (['government_id', 'qualification_certificate', 'guarantor_id', 'guarantor_form'] as $kind) {
            if (! in_array($kind, $files, true)) {
                $background[] = AgentApplication::FILE_KINDS[$kind];
            }
        }
        if ($background !== []) {
            $missing['background'] = $background;
        }

        $device = $application->device ?? [];
        $phone = [];
        if (! isset($device['ownership'])) {
            $phone[] = 'Whose phone it is';
        }
        if (! isset($device['gps_best_accuracy_m'])) {
            $phone[] = 'The location test';
        }
        if ($phone !== []) {
            $missing['phone'] = $phone;
        }

        if (count($application->consents) < count(AgentApplication::CONSENTS) || $application->signed_name === null) {
            $missing['agreements'] = ['The agreements and your signature'];
        }

        return $missing;
    }

    private function mustBeEditable(AgentApplication $application): void
    {
        if (! $application->editable()) {
            throw new RuntimeException('Your application has been sent, so it cannot be changed now. We will tell you if we need anything else.');
        }
    }

    private function mustBeWithin(int $lgaId, int $stateId, string $message): void
    {
        $ok = DB::table('admin_boundaries')
            ->where('id', $lgaId)->where('level', 'lga')->where('parent_id', $stateId)
            ->exists();

        if (! $ok) {
            throw new RuntimeException($message);
        }
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
