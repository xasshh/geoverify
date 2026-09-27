<?php

declare(strict_types=1);

namespace App\Domain\Investment\Actions;

use App\Domain\Investment\Models\DataRoomGrant;
use App\Domain\Investment\Models\WatchlistEntry;
use App\Domain\Registry\Actions\DirectoryVisibility;
use App\Domain\Verification\Enums\OrderStatus;
use App\Domain\Verification\Models\VerificationOrder;
use Illuminate\Support\Facades\DB;

/**
 * The investor overview's numbers.
 *
 * "Verified" means what a directory listing means by it (an officer recorded
 * the business and the record was not rejected, ResolveListingTier) and is
 * counted over the directory-visible population only, through
 * DirectoryVisibility, so a tile never counts what the rows would not badge. A count over
 * the register would describe businesses that never agreed to be described.
 *
 * Sectors are ISIC sections, the top of the taxonomy, because "share of
 * verified businesses" is only readable at a level where there are few enough
 * rows to compare. Shares are of classified businesses; the unclassified are
 * reported beside them rather than silently left out of the denominator.
 */
final class ReadInvestorOverview
{
    /** @return array<string, mixed> */
    public function __invoke(int $organisationId, ?string $state = null, ?string $sector = null): array
    {
        $bindings = [];
        $filter = '';

        if ($sector !== null && $sector !== '') {
            $filter .= ' AND sec.code = :sector';
            $bindings['sector'] = $sector;
        }

        $sectorJoin = '
            LEFT JOIN isic_classes grp ON grp.code = isic.parent_code
            LEFT JOIN isic_classes div ON div.code = grp.parent_code
            LEFT JOIN isic_classes sec ON sec.code = div.parent_code
            LEFT JOIN admin_boundaries st ON st.id = s.state_id
        ';

        $verified = "s.origin = 'field'";

        $states = DB::select('
            SELECT st.name AS state, count(*) AS verified
            '.DirectoryVisibility::FROM.$sectorJoin.'
            WHERE '.DirectoryVisibility::WHERE.' AND '.$verified.$filter.'
              AND st.name IS NOT NULL
            GROUP BY st.name
        ', $bindings);

        $opportunities = DB::select('
            SELECT st.name AS state, count(*) AS open
            '.ReadOpportunities::FROM.'
            WHERE '.ReadOpportunities::WHERE.'
              AND st.name IS NOT NULL
            GROUP BY st.name
        ');

        $stateBindings = $bindings;
        $stateFilter = $filter;

        if ($state !== null && $state !== '') {
            $stateFilter .= ' AND st.name = :state';
            $stateBindings['state'] = $state;
        }

        $sectors = DB::select('
            SELECT sec.code AS code, sec.name AS name, count(*) AS n
            '.DirectoryVisibility::FROM.$sectorJoin.'
            WHERE '.DirectoryVisibility::WHERE.' AND '.$verified.$stateFilter.'
            GROUP BY sec.code, sec.name
            ORDER BY n DESC
        ', $stateBindings);

        $classified = array_sum(array_map(
            static fn (object $r): int => $r->code === null ? 0 : (int) $r->n,
            $sectors,
        ));

        $openByState = [];

        foreach ($opportunities as $row) {
            $openByState[(string) $row->state] = (int) $row->open;
        }

        return [
            'states' => array_map(static fn (object $r): array => [
                'state' => (string) $r->state,
                'verified' => (int) $r->verified,
                'open' => $openByState[(string) $r->state] ?? 0,
            ], $states),
            'openByState' => $openByState,
            'sectors' => array_values(array_map(
                static fn (object $r): array => [
                    'code' => (string) $r->code,
                    'name' => (string) $r->name,
                    'count' => (int) $r->n,
                    'share' => $classified === 0 ? 0 : (int) round(100 * (int) $r->n / $classified),
                ],
                array_slice(array_values(array_filter($sectors, static fn (object $r): bool => $r->code !== null)), 0, 7),
            )),
            'unclassified' => array_sum(array_map(
                static fn (object $r): int => $r->code === null ? (int) $r->n : 0,
                $sectors,
            )),
            'counts' => [
                'watchlist' => WatchlistEntry::query()
                    ->where('investor_organisation_id', $organisationId)
                    ->whereNull('removed_at')
                    ->count(),
                'dataRooms' => DataRoomGrant::query()
                    ->where('investor_organisation_id', $organisationId)
                    ->where('status', DataRoomGrant::STATUS_GRANTED)
                    ->count(),
                // Visits this organisation paid for that an agent has not yet
                // finished: paid, assigned, on site or awaiting review.
                'verifications' => VerificationOrder::query()
                    ->where('investor_organisation_id', $organisationId)
                    ->whereIn('status', [
                        OrderStatus::Paid->value,
                        OrderStatus::Assigned->value,
                        OrderStatus::InProgress->value,
                        OrderStatus::Submitted->value,
                    ])
                    ->count(),
            ],
        ];
    }

    /** @return list<array{code: string, name: string}> */
    public function sectorOptions(): array
    {
        return array_map(
            static fn (object $r): array => ['code' => (string) $r->code, 'name' => (string) $r->name],
            DB::select("SELECT code, name FROM isic_classes WHERE level = 'section' ORDER BY name"),
        );
    }

    /** @return list<string> */
    public function stateOptions(): array
    {
        return array_map(
            static fn (object $r): string => (string) $r->name,
            DB::select("SELECT name FROM admin_boundaries WHERE level = 'state' ORDER BY name"),
        );
    }
}
