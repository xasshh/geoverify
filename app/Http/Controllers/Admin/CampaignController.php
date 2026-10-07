<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Campaign\Actions\AcknowledgeCampaign;
use App\Domain\Campaign\Actions\AssembleCampaignDossier;
use App\Domain\Campaign\Actions\GenerateCampaignCode;
use App\Domain\Campaign\Actions\TransitionCampaign;
use App\Domain\Campaign\Enums\AttributeType;
use App\Domain\Campaign\Enums\CampaignFieldType;
use App\Domain\Campaign\Enums\CampaignStatus;
use App\Domain\Campaign\Enums\CaptureMode;
use App\Domain\Campaign\Enums\EngagementStatus;
use App\Domain\Campaign\Enums\GeometryType;
use App\Domain\Campaign\Enums\PaymentStatus;
use App\Domain\Campaign\Enums\StakeholderCategory;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\CampaignCommercial;
use App\Domain\Campaign\Models\ClientOrganisation;
use App\Domain\Coverage\Models\CoverageArea;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Campaigns, from the inside.
 *
 * Everything a client sees, plus the two things they never do: what the exercise
 * is worth, and what we wrote to ourselves about it. Those arrive through their
 * own relationship on this controller and through nothing else in the codebase.
 */
final class CampaignController
{
    /** Every campaign, across every client. */
    public function index(Request $request): Response
    {
        $filters = $request->only(['client', 'status', 'state', 'from', 'to']);

        $campaigns = Campaign::query()
            ->with('organisation')
            ->withCount('coverageAreas')
            ->when($filters['client'] ?? null, fn ($q, $v) => $q->where('client_organisation_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('starts_on', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('ends_on', '<=', $v))
            // State lives on the mandates under a campaign, not on the campaign,
            // so it filters through the ground rather than through a duplicate
            // column that would have to be kept in step.
            ->when($filters['state'] ?? null, fn ($q, $v) => $q->whereHas(
                'coverageAreas',
                fn ($areas) => $areas->where('state_code', $v),
            ))
            ->orderByDesc('starts_on')
            ->get();

        return Inertia::render('admin/Campaigns', [
            'filters' => $filters,
            'campaigns' => $campaigns->map(static fn (Campaign $campaign): array => [
                'id' => $campaign->id,
                'code' => $campaign->code,
                'name' => $campaign->name,
                'subjectType' => $campaign->subject_type,
                'client' => $campaign->organisation?->name,
                'status' => $campaign->status->value,
                'statusLabel' => $campaign->status->label(),
                'startsOn' => $campaign->starts_on?->toDateString(),
                'endsOn' => $campaign->ends_on?->toDateString(),
                'areaCount' => $campaign->coverage_areas_count,
                'targetRecordCount' => $campaign->target_record_count,
            ])->values()->all(),
            'clients' => ClientOrganisation::query()->orderBy('name')->get(['id', 'name'])
                ->map(static fn (ClientOrganisation $c): array => ['id' => $c->id, 'name' => $c->name])->all(),
            'statuses' => CampaignStatus::options(),
            'states' => DB::table('coverage_areas')->whereNotNull('state_code')
                ->distinct()->orderBy('state_code')->pluck('state_code')->all(),
        ]);
    }

    /**
     * One campaign, in the seven sections it is built from.
     *
     * Definition, scope, schema, stakeholders, deployment, commercials, review.
     * Tabs rather than a wizard: a wizard is right the first time through and
     * wrong every time after, and this screen is opened far more often to change
     * one stakeholder than to write a campaign from nothing.
     */
    public function show(Campaign $campaign, AssembleCampaignDossier $dossier): Response
    {
        Gate::authorize('view', $campaign);

        $campaign->loadMissing('commercial');

        return Inertia::render('admin/Campaign', [
            // Internal stakeholders included: this is the inside view.
            'campaign' => $dossier($campaign, includeInternal: true),
            'commercial' => Gate::allows('viewCommercials', $campaign)
                ? $this->commercialsFor($campaign)
                : null,
            'allowedTransitions' => array_map(
                static fn (CampaignStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                $campaign->status->allows(),
            ),
            'unassignedAreas' => CoverageArea::query()
                ->whereNull('campaign_id')->orderBy('name')->get(['id', 'name'])
                ->map(static fn (CoverageArea $a): array => ['id' => $a->id, 'name' => $a->name])->all(),
            'officers' => User::query()->where('role', 'officer')
                ->where('status', User::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name', 'staff_ref'])
                ->map(static fn (User $u): array => [
                    'id' => $u->id, 'name' => $u->name, 'staffRef' => $u->staff_ref,
                ])->all(),
            'vocabulary' => [
                'fieldTypes' => CampaignFieldType::options(),
                'categories' => StakeholderCategory::options(),
                'engagement' => EngagementStatus::options(),
                'payment' => PaymentStatus::options(),
                'captureModes' => CaptureMode::options(),
                'geometryTypes' => GeometryType::options(),
                'attributeTypes' => AttributeType::options(),
            ],
        ]);
    }

    public function store(Request $request, GenerateCampaignCode $codes): RedirectResponse
    {
        Gate::authorize('create', Campaign::class);

        $data = $request->validate([
            'client_organisation_id' => ['required', 'integer', 'exists:client_organisations,id'],
            'name' => ['required', 'string', 'max:200'],
            'subject_type' => ['required', 'string', 'max:120'],
            'objective' => ['nullable', 'string', 'max:1000'],
            'about' => ['nullable', 'string', 'max:20000'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'target_record_count' => ['nullable', 'integer', 'min:1', 'max:10000000'],
        ]);

        $client = ClientOrganisation::query()->findOrFail($data['client_organisation_id']);

        $campaign = Campaign::query()->create([
            ...$data,
            'code' => $codes($client, $data['subject_type']),
            'status' => CampaignStatus::Draft,
            'created_by' => $this->admin()->id,
        ]);

        return to_route('admin.campaigns.show', $campaign)->with('status', "{$campaign->code} created.");
    }

    public function update(Request $request, Campaign $campaign, AcknowledgeCampaign $acknowledge): RedirectResponse
    {
        Gate::authorize('update', $campaign);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'subject_type' => ['required', 'string', 'max:120'],
            'objective' => ['nullable', 'string', 'max:1000'],
            'about' => ['nullable', 'string', 'max:20000'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'target_record_count' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            // Ticked by the person making the change, not inferred. Re-showing a
            // brief because somebody fixed a typo teaches everyone to dismiss it
            // without reading, which costs more than the typo did.
            'materially_revised' => ['nullable', 'boolean'],
        ]);

        $campaign->update(array_diff_key($data, array_flip(['materially_revised'])));

        if ($request->boolean('materially_revised')) {
            $acknowledge->requireReacknowledgement($campaign);
        }

        return back()->with('status', 'Campaign updated.');
    }

    public function transition(Request $request, Campaign $campaign, TransitionCampaign $transition): RedirectResponse
    {
        Gate::authorize('transition', $campaign);

        $data = $request->validate([
            'status' => ['required', Rule::enum(CampaignStatus::class)],
            'note' => ['nullable', 'string', 'max:500'],
            'override_start_date' => ['nullable', 'boolean'],
        ]);

        try {
            $transition(
                $campaign,
                CampaignStatus::from($data['status']),
                $this->admin(),
                $data['note'] ?? null,
                $request->boolean('override_start_date'),
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back()->with('status', 'Campaign moved.');
    }

    public function commercials(Request $request, Campaign $campaign): RedirectResponse
    {
        Gate::authorize('viewCommercials', $campaign);

        $data = $request->validate([
            'contract_value' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'currency' => ['required', 'string', 'size:3'],
            'payment_status' => ['required', Rule::enum(PaymentStatus::class)],
            'paid_at' => ['nullable', 'date'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        CampaignCommercial::query()->updateOrCreate(['campaign_id' => $campaign->id], $data);

        return back()->with('status', 'Commercials saved.');
    }

    /**
     * The commercial tab's payload.
     *
     * Reached only from show(), and only behind the viewCommercials gate. It is
     * the one method in the codebase that reads campaign_commercials for a
     * screen, which is what makes "did this leak" a question with one place to
     * look.
     *
     * @return array<string, mixed>
     */
    private function commercialsFor(Campaign $campaign): array
    {
        $commercial = $campaign->commercial;

        if ($commercial === null) {
            return [
                'contractValue' => null,
                'currency' => 'NGN',
                'paymentStatus' => PaymentStatus::Unpaid->value,
                'paidAt' => null,
                'internalNotes' => null,
            ];
        }

        return [
            'contractValue' => $commercial->contract_value,
            'currency' => $commercial->currency,
            'paymentStatus' => $commercial->payment_status->value,
            'paidAt' => $commercial->paid_at?->toDateString(),
            'internalNotes' => $commercial->internal_notes,
        ];
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user;
    }
}
