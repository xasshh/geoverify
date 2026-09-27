<?php

declare(strict_types=1);

namespace App\Domain\Investment\Actions;

use App\Domain\Identity\Models\IdentityClaim;
use App\Domain\Investment\Models\DataRoomDocument;
use App\Domain\Investment\Models\DataRoomGrant;
use App\Domain\Investment\Models\InvestorInterest;
use App\Domain\Investment\Models\InvestorNote;
use App\Domain\Investment\Models\WatchlistEntry;
use App\Domain\Party\Models\Party;
use App\Domain\Registry\Actions\DirectoryVisibility;
use App\Domain\Registry\Models\Enterprise;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The business dossier, for a verified investor.
 *
 * Built on ReadOpportunities::one, so the dossier cannot show an opportunity the
 * list would not, and extended with what only a dossier carries: the evidence
 * timeline, the catchment, the documents and the organisation's own notes.
 *
 * The catchment is computed in PostgreSQL from the real position and leaves as
 * rounded distances and counts. The position itself leaves as an H3 cell at
 * resolution 7, about five square kilometres: enough to judge a location, not
 * enough to find a gate. Counts are taken over the directory-visible population
 * only, never the register, for the reason the directory gives.
 */
final class ReadDossier
{
    public const TIER_LABEL = [
        'listed' => 'Listed',
        'identity_verified' => 'Identity verified',
        'location_verified' => 'Site verification',
        'operations_verified' => 'Operations verified',
        'monitored' => 'Monitoring visit',
    ];

    public function __construct(private readonly ReadOpportunities $opportunities) {}

    /** @return array<string, mixed>|null */
    public function __invoke(int $opportunityId, int $organisationId): ?array
    {
        $base = $this->opportunities->one($opportunityId, $organisationId);

        if ($base === null) {
            return null;
        }

        /** @var object $raw */
        $raw = $base['raw'];
        /** @var list<object> $orders */
        $orders = $base['orders'];
        unset($base['raw'], $base['orders']);

        $enterprise = Enterprise::query()->findOrFail((int) $raw->enterprise_id);
        $party = Party::query()->find((int) $raw->party_id);

        $cac = $this->identity($enterprise, $party, 'cac');

        $completed = array_values(array_filter($orders, static fn (object $o): bool => $o->status === 'completed'));
        $certificate = $completed === [] ? null : DB::selectOne(
            "SELECT id, reference, completed_at FROM verification_orders
             WHERE enterprise_id = ? AND status = 'completed'
             ORDER BY completed_at DESC LIMIT 1",
            [$enterprise->id],
        );

        $grant = DataRoomGrant::query()
            ->where('opportunity_id', $opportunityId)
            ->where('investor_organisation_id', $organisationId)
            ->first();

        $documents = DataRoomDocument::query()
            ->where('opportunity_id', $opportunityId)
            ->where('status', DataRoomDocument::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->get()
            ->map(static fn (DataRoomDocument $d): array => [
                'id' => $d->id,
                'title' => $d->title,
                'description' => $d->description ?? 'Shared by the business',
                'open' => $grant?->isGranted() === true,
            ])
            ->all();

        $watching = WatchlistEntry::query()
            ->where('investor_organisation_id', $organisationId)
            ->where('opportunity_id', $opportunityId)
            ->whereNull('removed_at')
            ->exists();

        $interested = InvestorInterest::query()
            ->where('opportunity_id', $opportunityId)
            ->count();

        $note = InvestorNote::query()
            ->where('investor_organisation_id', $organisationId)
            ->where('opportunity_id', $opportunityId)
            ->value('body');

        $since = $raw->operating_since === null ? null : (int) $raw->operating_since;

        return array_merge($base, [
            'cell' => $raw->cell === null ? null : (string) $raw->cell,
            'facts' => [
                'sector' => $base['sector'] ?? 'Not classified',
                'operatingSince' => $since === null
                    ? 'Not stated'
                    : $since.' · '.max(0, (int) now()->year - $since).' years',
                'staffOnSite' => $raw->staff_on_site ?? 'Not stated',
                'cac' => $cac,
                'premises' => $raw->premises ?? ucfirst(str_replace('_', ' ', (string) $raw->structure_type)),
                'lastVerified' => $base['lastVerified'],
            ],
            'seeking' => [
                'label' => $base['seeking'],
                'ticketSizeNaira' => $raw->ticket_size_minor === null ? null : intdiv((int) $raw->ticket_size_minor, 100),
                'useOfFunds' => $raw->use_of_funds,
                'summary' => $raw->summary,
                'interested' => $interested,
            ],
            'evidence' => $this->evidence($enterprise, $raw, $completed, $cac),
            'catchment' => $this->catchment((int) $raw->structure_id),
            'certificate' => $certificate === null ? null : [
                'orderId' => (int) $certificate->id,
                'reference' => (string) $certificate->reference,
                'issuedOn' => Carbon::parse((string) $certificate->completed_at)->toDateString(),
            ],
            'documents' => $documents,
            'grant' => $grant === null ? null : ['status' => $grant->status],
            'watching' => $watching,
            'interestedByUs' => InvestorInterest::query()
                ->where('opportunity_id', $opportunityId)
                ->where('investor_organisation_id', $organisationId)
                ->exists(),
            'note' => $note,
        ]);
    }

    /**
     * An identity reference, masked, with what was established about it. Only
     * the last four characters were ever kept.
     *
     * @return array{label: string, status: string}
     */
    private function identity(Enterprise $enterprise, ?Party $party, string $kind): array
    {
        $claim = IdentityClaim::query()
            ->where('kind', $kind)
            ->where(function ($q) use ($enterprise, $party): void {
                $q->where(function ($q) use ($enterprise): void {
                    $q->where('claimable_type', $enterprise->getMorphClass())->where('claimable_id', $enterprise->id);
                });

                if ($party !== null) {
                    $q->orWhere(function ($q) use ($party): void {
                        $q->where('claimable_type', $party->getMorphClass())->where('claimable_id', $party->id);
                    });
                }
            })
            ->orderByRaw("CASE status WHEN 'verified' THEN 0 WHEN 'declared' THEN 1 ELSE 2 END")
            ->first();

        if ($claim === null) {
            return ['label' => 'Not provided', 'status' => 'none'];
        }

        $status = (string) $claim->status;

        $standing = match ($status) {
            IdentityClaim::STATUS_VERIFIED => 'active',
            IdentityClaim::STATUS_DECLARED => 'declared',
            default => $status,
        };
        $last4 = (string) $claim->reference_last4;

        return [
            'label' => ($last4 === '' ? 'Registered' : 'RC ••••'.$last4).' · '.$standing,
            'status' => $status,
        ];
    }

    /**
     * What has been established, newest first, one line each.
     *
     * @param  list<object>  $completed
     * @param  array{label: string, status: string}  $cac
     * @return list<array{kind: string, title: string, detail: string, at: string}>
     */
    private function evidence(Enterprise $enterprise, object $raw, array $completed, array $cac): array
    {
        $items = [];

        foreach ($completed as $order) {
            $items[] = [
                'kind' => 'site',
                'title' => self::TIER_LABEL[(string) $order->tier] ?? 'Verification',
                'detail' => 'An officer attended and the report was accepted',
                'at' => Carbon::parse((string) $order->completed_at)->toIso8601String(),
            ];
        }

        $claimed = DB::selectOne(
            "SELECT established_at FROM party_businesses
             WHERE enterprise_id = ? AND party_id = ? AND status = 'active'",
            [$enterprise->id, (int) $raw->party_id],
        );

        if ($claimed !== null && $claimed->established_at !== null) {
            $items[] = [
                'kind' => 'owner',
                'title' => 'Owner proved control',
                'detail' => 'By the phone number on the record',
                'at' => Carbon::parse((string) $claimed->established_at)->toIso8601String(),
            ];
        }

        if ($cac['status'] === IdentityClaim::STATUS_VERIFIED) {
            $items[] = [
                'kind' => 'registry',
                'title' => 'CAC registration matched',
                'detail' => 'Registry check passed',
                'at' => Carbon::parse((string) $enterprise->updated_at)->toIso8601String(),
            ];
        }

        $items[] = [
            'kind' => 'enumeration',
            'title' => $raw->origin === 'field' ? 'First enumeration' : 'Added by its owner',
            'detail' => $raw->origin === 'field' ? 'Field enumeration campaign' : 'Self registration',
            'at' => Carbon::parse((string) $raw->captured_at)->toIso8601String(),
        ];

        usort($items, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return $items;
    }

    /**
     * Distances and counts around the business, measured in the database.
     *
     * @return array{highwayKm: float|null, sameTrade5km: int, verified15km: int}
     */
    private function catchment(int $structureId): array
    {
        $highway = DB::selectOne("
            SELECT ST_Distance(r.geometry::geography, s.centroid) AS metres
            FROM structures s
            CROSS JOIN LATERAL (
                SELECT geometry FROM roads
                WHERE highway IN ('motorway', 'trunk', 'primary')
                ORDER BY geometry <-> s.centroid::geometry
                LIMIT 1
            ) r
            WHERE s.id = ?
        ", [$structureId]);

        $counts = DB::selectOne('
            SELECT
                count(*) FILTER (
                    WHERE ST_DWithin(s.centroid, here.centroid, 5000)
                      AND e.sector_code IS NOT NULL
                      AND e.sector_code = (SELECT sector_code FROM enterprises WHERE structure_id = here.id LIMIT 1)
                ) AS same_trade,
                count(*) FILTER (
                    WHERE ST_DWithin(s.centroid, here.centroid, 15000)
                      AND s.origin = \'field\'
                ) AS verified
            '.DirectoryVisibility::FROM.'
            CROSS JOIN (SELECT id, centroid FROM structures WHERE id = :id) here
            WHERE '.DirectoryVisibility::WHERE.'
              AND s.id <> here.id
        ', ['id' => $structureId]);

        return [
            'highwayKm' => $highway === null ? null : round(((float) $highway->metres) / 1000, 1),
            'sameTrade5km' => (int) ($counts->same_trade ?? 0),
            'verified15km' => (int) ($counts->verified ?? 0),
        ];
    }
}
