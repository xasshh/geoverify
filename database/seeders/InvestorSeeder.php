<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Investment\Enums\InvestorKind;
use App\Domain\Investment\Models\DataRoomDocument;
use App\Domain\Investment\Models\InvestorOrganisation;
use App\Domain\Investment\Models\InvestorUser;
use App\Domain\Investment\Models\Opportunity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A verified investor, and opportunities on the businesses already claimed in
 * the development register, so the investor portal has something to show.
 *
 * Local only. It publishes on behalf of businesses, which in any real
 * environment only the business itself may do.
 *
 * Sign in at /invest/sign-in as investor@geoverify.test, password "password".
 */
final class InvestorSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->isLocal()) {
            $this->command?->error('InvestorSeeder is for local development only.');

            return;
        }

        $organisation = InvestorOrganisation::query()->updateOrCreate(
            ['name' => 'Harbour Capital'],
            [
                'kind' => InvestorKind::Fund,
                'website' => 'https://harbour.example',
                'kyc_status' => InvestorOrganisation::KYC_VERIFIED,
                'kyc_decided_at' => now(),
                'kyc_note' => 'Seeded for local development.',
            ],
        );

        InvestorUser::query()->updateOrCreate(
            ['email' => 'investor@geoverify.test'],
            [
                'investor_organisation_id' => $organisation->id,
                'name' => 'Kemi Adeyemi',
                'title' => 'Analyst',
                'password' => 'password',
                'status' => InvestorUser::STATUS_ACTIVE,
            ],
        );

        $profiles = [
            ['expansion_equity', 250_000_000, 'A second production line and a cold room', 2014, '60 to 80', '2.4 ha, owner occupied'],
            ['growth_equity', 120_000_000, 'Two more outlets in Garki and Wuse', 2020, '25 to 30', 'Leased shop and store'],
            ['asset_finance', 45_000_000, 'Delivery vans and a generator', 2017, '12 to 15', 'Leased commercial unit'],
        ];

        $controls = PartyBusiness::query()
            ->where('status', PartyBusiness::STATUS_ACTIVE)
            ->orderBy('enterprise_id')
            ->limit(count($profiles))
            ->get();

        foreach ($controls as $index => $control) {
            [$seeking, $ticket, $use, $since, $staff, $premises] = $profiles[$index];

            $opportunity = Opportunity::query()->updateOrCreate(
                ['enterprise_id' => $control->enterprise_id, 'status' => Opportunity::STATUS_PUBLISHED],
                [
                    'party_id' => $control->party_id,
                    'seeking' => $seeking,
                    'ticket_size_minor' => $ticket * 100,
                    'use_of_funds' => $use,
                    'summary' => 'Trading steadily since '.$since.' with regular suppliers and a growing customer base.',
                    'operating_since' => $since,
                    'staff_on_site' => $staff,
                    'premises' => $premises,
                    'published_at' => now()->subDays(10 - $index),
                ],
            );

            if ($opportunity->documents()->doesntExist()) {
                $account = DB::table('party_users')->where('party_id', $control->party_id)->value('portal_account_id');

                foreach (['Audited accounts 2024 to 2025', 'Management accounts, H1 2026', 'Land title / C of O'] as $title) {
                    $path = 'data-rooms/'.$opportunity->id.'/'.str($title)->slug().'.pdf';
                    Storage::disk('media')->put($path, "%PDF-1.4\n% Seeded placeholder for {$title}\n");

                    DataRoomDocument::query()->create([
                        'opportunity_id' => $opportunity->id,
                        'title' => $title,
                        'description' => 'Shared by the business',
                        'disk' => 'media',
                        'path' => $path,
                        'mime' => 'application/pdf',
                        'bytes' => 64,
                        'uploaded_by_party_id' => $control->party_id,
                        'uploaded_by_account_id' => $account,
                        'status' => DataRoomDocument::STATUS_ACTIVE,
                    ]);
                }
            }
        }

        $this->command?->info('Investor: investor@geoverify.test / password. Opportunities published: '.$controls->count().'.');
    }
}
