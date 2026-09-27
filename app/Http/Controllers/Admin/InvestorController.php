<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Investment\Actions\DecideInvestorKyc;
use App\Domain\Investment\Models\InvestorOrganisation;
use App\Domain\Investment\Models\InvestorUser;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Investor organisations waiting on KYC, and the ruling on each.
 */
final class InvestorController
{
    public function index(): Response
    {
        return Inertia::render('admin/Investors', [
            'organisations' => InvestorOrganisation::query()
                ->with('users')
                ->orderByRaw("CASE kyc_status WHEN 'pending' THEN 0 WHEN 'verified' THEN 1 ELSE 2 END")
                ->orderByDesc('created_at')
                ->get()
                ->map(static fn (InvestorOrganisation $o): array => [
                    'id' => $o->id,
                    'name' => $o->name,
                    'kind' => $o->kind->label(),
                    'website' => $o->getAttribute('website'),
                    'kycStatus' => $o->kyc_status,
                    'decidedAt' => $o->kyc_decided_at?->toIso8601String(),
                    'note' => $o->getAttribute('kyc_note'),
                    'requestedAt' => $o->created_at?->toIso8601String(),
                    'people' => $o->users->map(static fn (InvestorUser $u): array => [
                        'name' => $u->name,
                        'title' => $u->title,
                        'email' => $u->email,
                    ])->all(),
                ])
                ->all(),
        ]);
    }

    public function decide(Request $request, InvestorOrganisation $organisation, DecideInvestorKyc $decide): RedirectResponse
    {
        $input = $request->validate([
            'decision' => ['required', Rule::in([InvestorOrganisation::KYC_VERIFIED, InvestorOrganisation::KYC_SUSPENDED])],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $admin = $request->user();
        abort_unless($admin instanceof User, 403);

        $decide($organisation, $input['decision'], $admin, $input['note']);

        return back()->with('status', $input['decision'] === InvestorOrganisation::KYC_VERIFIED
            ? $organisation->name.' is verified.'
            : $organisation->name.' is suspended.');
    }
}
