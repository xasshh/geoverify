<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Actions;

use App\Domain\Campaign\Actions\AssembleCampaignDossier;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Enumerate\Models\EnumerateMember;
use App\Domain\Enumerate\Models\EnumerateProject;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Custom enumeration projects (board 35), from the organisation's request to
 * the live view of the campaign that carries them out.
 *
 * The organisation says what it wants collected, where, how many and by when.
 * The account manager scopes it and runs it as a campaign (CAMPAIGNS.md), and
 * links the two. From then on the organisation sees the campaign's progress
 * as AssembleCampaignDossier gives it to any client: records against target,
 * the ground and its cells, how many officers are out, the declared fields.
 * Never the commercials, which that assembler has no path to, and never an
 * officer's name: their staff code at most.
 *
 * The fields asked for are a specification for the account manager. Officers'
 * phones collect what the campaign declares, and custom field types reach the
 * field app after launch, as versioned forms (decision 3).
 */
final class ManageProjects
{
    public function __construct(
        private readonly MintReference $mint,
        private readonly AssembleCampaignDossier $dossier,
    ) {}

    /**
     * @param  array{name: string, subject: string, area: string, target_records?: int|null, wanted_by?: string|null, notes?: string|null, fields: list<array{label: string, type: string}>}  $data
     */
    public function request(EnumerateMember $member, PortalAccount $account, array $data): EnumerateProject
    {
        $organisation = $member->organisation()->firstOrFail();

        if (! $member->may('project')) {
            throw new RuntimeException('Only an admin or a project lead starts a project.');
        }

        if (! $organisation->approved()) {
            throw new RuntimeException('Projects open once we have approved your organisation.');
        }

        $fields = [];

        foreach ($data['fields'] as $field) {
            $label = trim($field['label']);

            if ($label === '') {
                continue;
            }

            if (! in_array($field['type'], EnumerateProject::FIELD_TYPES, true)) {
                throw new RuntimeException("Choose a type for “{$label}”.");
            }

            $fields[] = ['label' => mb_substr($label, 0, 80), 'type' => $field['type']];
        }

        if ($fields === [] || count($fields) > 40) {
            throw new RuntimeException('List between one and forty fields you want collected.');
        }

        if (mb_strlen(trim($data['name'])) < 3 || mb_strlen(trim($data['area'])) < 3 || mb_strlen(trim($data['subject'])) < 3) {
            throw new RuntimeException('Name the project, say what is being counted, and where.');
        }

        $wanted = isset($data['wanted_by']) && $data['wanted_by'] !== ''
            ? Carbon::parse($data['wanted_by'])
            : null;

        if ($wanted !== null && $wanted->isPast()) {
            throw new RuntimeException('Choose a date that has not passed.');
        }

        for ($attempt = 0; ; $attempt++) {
            try {
                $project = DB::transaction(fn (): EnumerateProject => EnumerateProject::query()->create([
                    'reference' => ($this->mint)('PRJ', 5),
                    'organisation_id' => $organisation->id,
                    'requested_by' => $account->id,
                    'name' => mb_substr(trim($data['name']), 0, 160),
                    'subject' => mb_substr(trim($data['subject']), 0, 120),
                    'area' => mb_substr(trim($data['area']), 0, 300),
                    'target_records' => isset($data['target_records']) && $data['target_records'] > 0 ? (int) $data['target_records'] : null,
                    'wanted_by' => $wanted?->toDateString(),
                    'fields' => $fields,
                    'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? mb_substr(trim((string) $data['notes']), 0, 2000) : null,
                    'status' => 'requested',
                ]));

                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 4) {
                    throw $e;
                }
            }
        }

        VerificationEvent::recordForBuyer($project, 'enumerate.project_requested', $account, ['organisation_id' => $organisation->id]);

        return $project;
    }

    /**
     * Staff move a project on: scoping, live on a campaign, closed, or
     * declined. Live and closed need the campaign it runs as.
     */
    public function move(EnumerateProject $project, User $staff, string $status, ?int $campaignId): EnumerateProject
    {
        if (! $staff->supervises()) {
            throw new RuntimeException('Only staff move a project on.');
        }

        if (! array_key_exists($status, EnumerateProject::STATUSES)) {
            throw new RuntimeException('That is not a project status.');
        }

        $campaignId ??= $project->campaign_id;

        if (in_array($status, ['live', 'closed'], true) && ($campaignId === null || ! Campaign::query()->whereKey($campaignId)->exists())) {
            throw new RuntimeException('Link the campaign that runs this project first.');
        }

        $project->update(['status' => $status, 'campaign_id' => $campaignId]);
        VerificationEvent::record($project, 'enumerate.project_moved', $staff, ['status' => $status, 'campaign_id' => $campaignId]);

        return $project;
    }

    /**
     * The live view, from the campaign, in what the organisation needs and no
     * more. Null until a campaign is linked.
     *
     * @return array<string, mixed>|null
     */
    public function progress(EnumerateProject $project): ?array
    {
        $campaign = $project->campaign;

        if ($campaign === null) {
            return null;
        }

        $dossier = ($this->dossier)($campaign);
        $collection = $dossier['collection'];
        $coverage = $dossier['coverage'];
        $deployment = $dossier['deployment'];
        $gathered = (int) ($collection['gathered'] ?? 0);

        return [
            'campaignCode' => $dossier['code'],
            'status' => $dossier['statusLabel'],
            'records' => $gathered,
            'accepted' => (int) ($collection['accepted'] ?? 0),
            'target' => $collection['target'] ?? $project->target_records,
            'percent' => $collection['percent'] ?? null,
            'qaPassRate' => $gathered === 0 ? null : (int) round(((int) ($collection['accepted'] ?? 0)) / $gathered * 100),
            'areas' => array_map(static fn (array $a): array => [
                'name' => $a['name'],
                'lga' => $a['lga'],
                'cells' => $a['cells'],
            ], $coverage['areas'] ?? []),
            'cells' => array_sum(array_map(static fn (array $a): int => (int) $a['cells'], $coverage['areas'] ?? [])),
            'officers' => (int) ($deployment['activeCount'] ?? 0),
            'officerCodes' => array_values(array_filter(array_map(static fn (array $r): ?string => $r['staffRef'] ?? null, $deployment['roster'] ?? []))),
            // The declared schema: what officers' phones actually collect.
            'fields' => array_map(static fn (array $f): array => [
                'label' => $f['label'],
                'type' => $f['typeLabel'],
                'required' => $f['isRequired'],
            ], $dossier['schema']['fields'] ?? []),
            'timeline' => $dossier['timeline'],
        ];
    }
}
