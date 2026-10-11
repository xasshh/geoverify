<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Identity\Models\IdentityClaim;
use App\Domain\Staff\Models\AgentApplication;
use App\Domain\Staff\Models\AgentApplicationFile;
use Illuminate\Support\Facades\DB;

/**
 * One shape for an application, read by the applicant and by an administrator.
 *
 * Both are entitled to everything in it: the applicant typed it, and the
 * administrator is the one person who rules on it. A NIN or BVN appears only
 * as its last four digits, because nothing more is kept.
 */
final class PresentAgentApplication
{
    public function __construct(private readonly ManageAgentApplication $manage) {}

    /** @return array<string, mixed> */
    public function page(AgentApplication $a): array
    {
        $claims = $a->identityClaims()->latest('id')->get()->unique('kind')
            ->mapWithKeys(fn (IdentityClaim $c): array => [$c->kind => $c->reference_last4])->all();

        $places = DB::table('admin_boundaries')
            ->whereIn('id', array_filter([$a->residence_state_id, $a->residence_lga_id, $a->origin_state_id, $a->origin_lga_id]))
            ->pluck('name', 'id');

        return [
            'reference' => $a->reference,
            'status' => $a->status,
            'editable' => $a->editable(),
            'submittedAt' => $a->submitted_at?->toIso8601String(),
            'decidedAt' => $a->decided_at?->toIso8601String(),
            'decisionNote' => $a->decision_note,
            'staffRef' => $a->officer?->staff_ref,
            'email' => $a->account?->email,
            'about' => [
                'surname' => $a->surname,
                'first_name' => $a->first_name,
                'middle_name' => $a->middle_name,
                'date_of_birth' => $a->date_of_birth?->toDateString(),
                'sex' => $a->sex,
                'phone' => $a->phone,
                'whatsapp' => $a->whatsapp,
                'residential_address' => $a->residential_address,
                'residence_state_id' => $a->residence_state_id,
                'residence_lga_id' => $a->residence_lga_id,
                'origin_state_id' => $a->origin_state_id,
                'origin_lga_id' => $a->origin_lga_id,
                'languages' => $a->languages,
            ],
            'places' => [
                'residence' => implode(', ', array_filter([$places[$a->residence_lga_id] ?? null, $places[$a->residence_state_id] ?? null])),
                'origin' => implode(', ', array_filter([$places[$a->origin_lga_id] ?? null, $places[$a->origin_state_id] ?? null])),
            ],
            'background' => [
                'qualification' => $a->qualification,
                'experience' => $a->experience,
                'experience_years' => $a->experience_years,
                'guarantor' => $a->guarantor,
                'next_of_kin' => $a->next_of_kin,
                'ninLast4' => $claims[IdentityClaim::KIND_NIN] ?? null,
                'bvnLast4' => $claims[IdentityClaim::KIND_BVN] ?? null,
            ],
            'device' => $a->device,
            'consents' => $a->consents,
            'signedName' => $a->signed_name,
            'files' => $a->currentFiles()->orderBy('kind')->get()->map(fn (AgentApplicationFile $f): array => [
                'id' => $f->id,
                'kind' => $f->kind,
                'label' => $f->label,
                'mime' => $f->mime,
                'sizeBytes' => $f->size_bytes,
                'uploadedAt' => $f->created_at->toIso8601String(),
            ])->values()->all(),
            'missing' => $this->manage->missing($a),
            'captureModes' => $a->capture_modes,
            'assessmentScore' => $a->assessment_score,
        ];
    }

    /** @return array<string, mixed> */
    public static function choices(): array
    {
        $states = DB::table('admin_boundaries')->where('level', 'state')->orderBy('name')->get(['id', 'name']);
        $lgas = DB::table('admin_boundaries')->where('level', 'lga')->orderBy('name')->get(['id', 'name', 'parent_id']);

        return [
            'states' => $states->map(fn (object $s): array => ['id' => (int) $s->id, 'name' => (string) $s->name])->all(),
            'lgas' => $lgas->map(fn (object $l): array => ['id' => (int) $l->id, 'name' => (string) $l->name, 'stateId' => (int) $l->parent_id])->all(),
            'languages' => AgentApplication::LANGUAGES,
            'qualifications' => AgentApplication::QUALIFICATIONS,
            'experience' => AgentApplication::EXPERIENCE,
            'consents' => AgentApplication::CONSENTS,
            'fileKinds' => AgentApplication::FILE_KINDS,
            'governmentIds' => AgentApplication::GOVERNMENT_IDS,
        ];
    }
}
