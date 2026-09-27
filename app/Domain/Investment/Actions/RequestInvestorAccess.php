<?php

declare(strict_types=1);

namespace App\Domain\Investment\Actions;

use App\Domain\Investment\Enums\InvestorKind;
use App\Domain\Investment\Models\InvestorOrganisation;
use App\Domain\Investment\Models\InvestorUser;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Facades\DB;

/**
 * An organisation asks to use the investor portal.
 *
 * The person can sign in at once and read the aggregates, which are no more
 * than the public directory already implies. Dossiers, data rooms and
 * commissions wait for an admin to verify the organisation.
 */
final class RequestInvestorAccess
{
    /**
     * @param  array{organisation: string, kind: string, website?: string|null, name: string, title?: string|null, email: string, password: string}  $input
     */
    public function __invoke(array $input): InvestorUser
    {
        return DB::transaction(function () use ($input): InvestorUser {
            $organisation = InvestorOrganisation::query()->create([
                'name' => $input['organisation'],
                'kind' => InvestorKind::from($input['kind']),
                'website' => $input['website'] ?? null,
                'kyc_status' => InvestorOrganisation::KYC_PENDING,
            ]);

            $user = InvestorUser::query()->create([
                'investor_organisation_id' => $organisation->id,
                'name' => $input['name'],
                'title' => $input['title'] ?? null,
                'email' => mb_strtolower($input['email']),
                'password' => $input['password'],
                'status' => InvestorUser::STATUS_ACTIVE,
            ]);

            VerificationEvent::recordForInvestor($organisation, 'investor.access_requested', $user);

            return $user;
        });
    }
}
