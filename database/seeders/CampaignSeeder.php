<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Campaign\Actions\GenerateCampaignCode;
use App\Domain\Campaign\Enums\CampaignFieldType;
use App\Domain\Campaign\Enums\CampaignStatus;
use App\Domain\Campaign\Enums\DeploymentStatus;
use App\Domain\Campaign\Enums\EngagementStatus;
use App\Domain\Campaign\Enums\PaymentStatus;
use App\Domain\Campaign\Enums\StakeholderCategory;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\CampaignAgentAssignment;
use App\Domain\Campaign\Models\CampaignCommercial;
use App\Domain\Campaign\Models\CampaignField;
use App\Domain\Campaign\Models\CampaignStakeholder;
use App\Domain\Campaign\Models\ClientOrganisation;
use App\Domain\Campaign\Models\ClientUser;
use App\Domain\Coverage\Models\CoverageArea;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One client, two exercises, and a home for the ground already walked.
 *
 * The second campaign is completed rather than a second active one, because the
 * screens that matter are the ones showing an exercise that is over: a client
 * list with only live rows in it never proves the archive works, and the
 * question "what did you do for us last year" is the one that renews a contract.
 *
 * Additive, like FieldDaySeeder. Running it twice does not duplicate: the client
 * and its campaigns are matched on their natural keys first.
 */
final class CampaignSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->isLocal()) {
            $this->command?->error('CampaignSeeder is for local development only.');

            return;
        }

        $client = ClientOrganisation::query()->firstOrCreate(
            ['short_code' => 'NRS'],
            [
                'name' => 'Nigeria Revenue Service',
                'contact_name' => 'Halima Abubakar',
                'contact_email' => 'halima.abubakar@nrs.gov.ng.test',
                'contact_phone' => '+2348030000001',
                'status' => ClientOrganisation::STATUS_ACTIVE,
            ],
        );

        ClientUser::query()->firstOrCreate(
            ['email' => 'client@nrs.test'],
            [
                'client_organisation_id' => $client->id,
                'name' => 'Halima Abubakar',
                'password' => 'password',
                'status' => ClientUser::STATUS_ACTIVE,
            ],
        );

        $mining = $this->mining($client);
        $this->traders($client);

        $this->command?->info("Seeded {$client->name} with 2 campaigns.");
        $this->command?->line('  Client sign in: client@nrs.test / password');
        $this->command?->line("  Active campaign: {$mining->code}");
    }

    /** The live one: mining companies, across the ground already enumerated. */
    private function mining(ClientOrganisation $client): Campaign
    {
        $admin = User::query()->where('role', Role::Admin->value)->first();

        $campaign = Campaign::query()->firstOrCreate(
            ['client_organisation_id' => $client->id, 'name' => 'Mining Company Enumeration, North Central'],
            [
                'code' => app(GenerateCampaignCode::class)($client, 'Mining companies'),
                'subject_type' => 'Mining companies',
                'objective' => 'Establish a verified register of mining operators in the North Central zone, with a confirmed position and a named responsible officer for each.',
                'about' => <<<'TEXT'
                The Service holds licence records for mining operators but no independent
                confirmation that the licensed address is where the operator actually works
                from. Several enforcement actions in the last two years failed at service of
                process because the address on file was a correspondence address, a closed
                site, or a plot that had never been developed.

                This exercise sends field officers to each recorded location, confirms whether
                an operator is present and trading, records a GPS position taken on site, and
                photographs the frontage and any signage. Where the operator is absent, the
                officer records what is there instead, which is itself the finding.

                Coverage is the Federal Capital Territory first, with the remaining North
                Central states to follow as the boundary data is loaded. Every record carries
                the officer, the device, the walking trace and a confidence score, so the
                Service can see not only what was found but how well it was established.
                TEXT,
                'status' => CampaignStatus::Active,
                'starts_on' => now()->subDays(24)->toDateString(),
                'ends_on' => now()->addDays(66)->toDateString(),
                'target_record_count' => 2_400,
                'created_by' => $admin?->id,
                'approved_by' => $admin?->id,
                'approved_at' => now()->subDays(30),
            ],
        );

        CampaignCommercial::query()->firstOrCreate(
            ['campaign_id' => $campaign->id],
            [
                'contract_value' => 48_500_000,
                'currency' => 'NGN',
                'payment_status' => PaymentStatus::PartPaid,
                'internal_notes' => 'Mobilisation invoice settled. Balance due on delivery of the first 1,200 verified records. Margin is thin at this rate: hold the line on any scope addition.',
            ],
        );

        // Every mandate already walked belongs to this exercise. That is the
        // backfill: nothing captured in Phase 1 is left without a campaign, and
        // no coverage area had to move to get one.
        CoverageArea::query()->whereNull('campaign_id')->update([
            'campaign_id' => $campaign->id,
        ]);

        CoverageArea::query()
            ->where('campaign_id', $campaign->id)
            ->whereNull('target_record_count')
            ->update(['target_record_count' => 2_400]);

        $this->schemaFor($campaign);
        $this->stakeholdersFor($campaign);
        $this->deployTo($campaign);

        return $campaign;
    }

    /** The finished one, so the archive has something in it. */
    private function traders(ClientOrganisation $client): Campaign
    {
        $admin = User::query()->where('role', Role::Admin->value)->first();

        $campaign = Campaign::query()->firstOrCreate(
            ['client_organisation_id' => $client->id, 'name' => 'Hair Extension Trader Count, Wuse and Garki'],
            [
                'code' => app(GenerateCampaignCode::class)($client, 'Hair extension traders'),
                'subject_type' => 'Hair extension traders',
                'objective' => 'Size the informal trade in hair extensions across two Abuja markets ahead of a proposed levy.',
                'about' => <<<'TEXT'
                A short exercise run over six weeks to establish how many traders operate in
                the hair extension trade across Wuse and Garki markets, at what scale, and
                from what kind of premises.

                The count found substantially more traders than the market association's own
                figures, most of them operating from lock-up shops rather than the open stalls
                the association records. The gap is the finding, and it is the reason the
                proposed levy was re-modelled before it went to consultation.
                TEXT,
                'status' => CampaignStatus::Completed,
                'starts_on' => now()->subMonths(8)->toDateString(),
                'ends_on' => now()->subMonths(6)->toDateString(),
                'target_record_count' => 900,
                'created_by' => $admin?->id,
                'approved_by' => $admin?->id,
                'approved_at' => now()->subMonths(9),
            ],
        );

        CampaignCommercial::query()->firstOrCreate(
            ['campaign_id' => $campaign->id],
            [
                'contract_value' => 9_200_000,
                'currency' => 'NGN',
                'payment_status' => PaymentStatus::Paid,
                'paid_at' => now()->subMonths(5),
                'internal_notes' => 'Paid in full, thirty days late. Worth doing again: low cost, and it opened the door to the mining work.',
            ],
        );

        return $campaign;
    }

    /** What the mining exercise says it is collecting. */
    private function schemaFor(Campaign $campaign): void
    {
        $fields = [
            ['Operator name', 'operator_name', CampaignFieldType::Text, true, 'The name on the signage, exactly as written.'],
            ['Mining licence number', 'licence_number', CampaignFieldType::Text, true, 'As issued by the Mining Cadastre Office.'],
            ['Mineral worked', 'mineral', CampaignFieldType::Select, true, null],
            ['Site status', 'site_status', CampaignFieldType::Select, true, 'What the officer found on arrival.'],
            ['Responsible officer on site', 'responsible_officer', CampaignFieldType::Text, false, null],
            ['Contact phone', 'contact_phone', CampaignFieldType::Text, false, null],
            ['Staff present on the day', 'staff_present', CampaignFieldType::Number, false, null],
            ['Operating since', 'operating_since', CampaignFieldType::Date, false, null],
            ['Site photograph', 'site_photograph', CampaignFieldType::Photo, true, 'The frontage, taken from the road.'],
            ['Position', 'position', CampaignFieldType::GeoPoint, true, 'Recorded by the device, not typed.'],
            ['Licence sighted', 'licence_sighted', CampaignFieldType::Boolean, false, null],
        ];

        $options = [
            'mineral' => ['Tin', 'Lead and zinc', 'Barite', 'Gold', 'Limestone', 'Granite', 'Other'],
            'site_status' => ['Operating', 'Dormant', 'Abandoned', 'Never developed', 'Different occupant'],
        ];

        foreach ($fields as $order => [$label, $key, $type, $required, $help]) {
            CampaignField::query()->firstOrCreate(
                ['campaign_id' => $campaign->id, 'key' => $key],
                [
                    'label' => $label,
                    'type' => $type,
                    'options' => $options[$key] ?? null,
                    'is_required' => $required,
                    'sort_order' => $order,
                    'help_text' => $help,
                ],
            );
        }
    }

    /** Who has to be squared before officers walk. */
    private function stakeholdersFor(Campaign $campaign): void
    {
        $people = [
            ['Mining Cadastre Office', StakeholderCategory::GovernmentAgency, 'Federal Ministry of Solid Minerals', 'Director, Cadastre', 'Engr. Bala Yusuf', EngagementStatus::Engaged, true, 'Provided the licence register extract. Wants sight of findings before publication.'],
            ['FCT Administration', StakeholderCategory::StateGovernment, 'Federal Capital Territory Administration', 'Permanent Secretary', 'Mrs. Ngozi Eze', EngagementStatus::Engaged, true, 'Letters of introduction issued for all six area councils.'],
            ['Sarkin Gwagwa', StakeholderCategory::TraditionalAuthority, 'Gwagwa District', 'District Head', null, EngagementStatus::Contacted, true, 'Courtesy visit arranged. Officers should not enter Gwagwa before it happens.'],
            ['Miners Association of Nigeria', StakeholderCategory::CommunityGroup, 'MAN, FCT Chapter', 'Chapter Secretary', 'Mr. Sunday Okon', EngagementStatus::Identified, true, null],
            ['Nigerian Mining Journal', StakeholderCategory::Media, 'Nigerian Mining Journal', 'Editor', null, EngagementStatus::Declined, true, 'Asked to embargo until the Service publishes. Declined to commit.'],
            ['Aide to the Honourable Minister', StakeholderCategory::Other, 'Federal Ministry of Solid Minerals', 'Senior Special Assistant', null, EngagementStatus::Engaged, false, 'Unblocked the cadastre extract when the formal request stalled. Do not put this on a client-facing document.'],
        ];

        foreach ($people as [$name, $category, $organisation, $roleTitle, $contact, $status, $visible, $notes]) {
            CampaignStakeholder::query()->firstOrCreate(
                ['campaign_id' => $campaign->id, 'name' => $name],
                [
                    'category' => $category,
                    'organisation' => $organisation,
                    'role_title' => $roleTitle,
                    'contact_person' => $contact,
                    'phone' => '+23480'.random_int(10_000_000, 99_999_999),
                    'email' => null,
                    'engagement_status' => $status,
                    'visible_to_client' => $visible,
                    'notes' => $notes,
                ],
            );
        }
    }

    /** The officers already in the field, put on the exercise they are walking. */
    private function deployTo(Campaign $campaign): void
    {
        $area = CoverageArea::query()->where('campaign_id', $campaign->id)->first();
        $admin = User::query()->where('role', Role::Admin->value)->first();

        User::query()->where('role', Role::Officer->value)->get()
            ->each(function (User $officer) use ($campaign, $area, $admin): void {
                CampaignAgentAssignment::query()->firstOrCreate(
                    ['campaign_id' => $campaign->id, 'user_id' => $officer->id, 'unassigned_at' => null],
                    [
                        'coverage_area_id' => $area?->id,
                        'assigned_at' => now()->subDays(20),
                        'status' => DeploymentStatus::Active,
                        'assigned_by' => $admin?->id,
                    ],
                );
            });
    }
}
