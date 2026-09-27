<?php

declare(strict_types=1);

namespace App\Domain\Investment\Actions;

use App\Domain\Investment\Enums\Seeking;
use App\Domain\Registry\Actions\ResolveListingTier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every opportunity an investor can see, and nothing else.
 *
 * One FROM and one WHERE, shared by the list, the overview's featured table,
 * the watchlist and the dossier, for the reason the directory has
 * DirectoryVisibility: a count or a row computed from a slightly different
 * predicate is how a page shows a business that has since withdrawn.
 *
 * Visible means: the opportunity is published, the party that published it
 * still controls the business, the structure was not rejected, and the
 * business has not asked to be withheld. An opportunity is the business's own
 * act, so publication state is not consulted beyond withholding: publishing an
 * opportunity is the consent to be read by a verified investor.
 *
 * What never leaves, as in the directory: the phone, the email, an exact
 * coordinate and every photograph an officer took.
 */
final class ReadOpportunities
{
    public const FROM = <<<'SQL'
        FROM opportunities o
        JOIN enterprises e ON e.id = o.enterprise_id
        JOIN structures s ON s.id = e.structure_id
        LEFT JOIN admin_boundaries st  ON st.id  = s.state_id
        LEFT JOIN admin_boundaries lga ON lga.id = s.lga_id
        LEFT JOIN isic_classes cls ON cls.code = e.sector_code
        LEFT JOIN isic_classes grp ON grp.code = cls.parent_code
        LEFT JOIN isic_classes div ON div.code = grp.parent_code
        LEFT JOIN isic_classes sec ON sec.code = div.parent_code
        SQL;

    public const WHERE = <<<'SQL'
        o.status = 'published'
          AND s.status <> 'rejected'
          AND e.publication_state <> 'withheld'
          AND EXISTS (
                SELECT 1 FROM party_businesses pb
                WHERE pb.enterprise_id = e.id
                  AND pb.party_id = o.party_id
                  AND pb.status = 'active'
          )
        SQL;

    public function __construct(
        private readonly ResolveListingTier $tiers,
        private readonly ScoreVerification $score,
    ) {}

    /**
     * @param  array{state?: string|null, sector?: string|null, seeking?: string|null, ids?: list<int>|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function rows(int $organisationId, array $filters = [], int $limit = 100): array
    {
        $bindings = ['org' => $organisationId, 'limit' => $limit];
        $where = self::WHERE;

        if (($filters['state'] ?? null) !== null && $filters['state'] !== '') {
            $where .= ' AND st.name = :state';
            $bindings['state'] = $filters['state'];
        }

        if (($filters['sector'] ?? null) !== null && $filters['sector'] !== '') {
            $where .= ' AND sec.code = :sector';
            $bindings['sector'] = $filters['sector'];
        }

        if (($filters['seeking'] ?? null) !== null && $filters['seeking'] !== '') {
            $where .= ' AND o.seeking = :seeking';
            $bindings['seeking'] = $filters['seeking'];
        }

        if (array_key_exists('ids', $filters) && $filters['ids'] !== null) {
            if ($filters['ids'] === []) {
                return [];
            }

            $where .= ' AND o.id IN ('.implode(',', array_map('intval', $filters['ids'])).')';
        }

        $rows = DB::select('
            SELECT
                o.id, o.seeking, o.operating_since, o.published_at,
                e.id AS enterprise_id, e.trading_name, e.captured_at,
                s.origin, s.status AS structure_status,
                cls.name AS sector_class, sec.name AS sector, sec.code AS sector_code,
                st.name AS state, lga.name AS lga,
                g.status AS grant_status
            '.self::FROM.'
            LEFT JOIN data_room_grants g
                   ON g.opportunity_id = o.id AND g.investor_organisation_id = :org
            WHERE '.$where.'
            ORDER BY o.published_at DESC NULLS LAST, o.id DESC
            LIMIT :limit
        ', $bindings);

        $orders = $this->ordersFor(array_map(static fn (object $r): int => (int) $r->enterprise_id, $rows));

        $projected = array_map(fn (object $row): array => $this->project($row, $orders[(int) $row->enterprise_id] ?? []), $rows);

        usort($projected, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $projected;
    }

    /** @return array<string, mixed>|null */
    public function one(int $opportunityId, int $organisationId): ?array
    {
        $row = DB::selectOne('
            SELECT
                o.id, o.seeking, o.operating_since, o.published_at, o.ticket_size_minor, o.currency,
                o.use_of_funds, o.summary, o.staff_on_site, o.premises,
                e.id AS enterprise_id, e.trading_name, e.captured_at,
                s.id AS structure_id, s.origin, s.status AS structure_status, s.structure_type,
                cls.name AS sector_class, sec.name AS sector, sec.code AS sector_code,
                st.name AS state, lga.name AS lga,
                h3_cell_to_parent(s.h3_index::h3index, 7)::text AS cell,
                g.status AS grant_status,
                o.party_id
            '.self::FROM.'
            LEFT JOIN data_room_grants g
                   ON g.opportunity_id = o.id AND g.investor_organisation_id = :org
            WHERE '.self::WHERE.' AND o.id = :id
        ', ['org' => $organisationId, 'id' => $opportunityId]);

        if ($row === null) {
            return null;
        }

        $orders = $this->ordersFor([(int) $row->enterprise_id]);

        return $this->project($row, $orders[(int) $row->enterprise_id] ?? []) + ['raw' => $row, 'orders' => $orders[(int) $row->enterprise_id] ?? []];
    }

    /**
     * The rungs of one business, from the record and from what has been
     * bought since: the ladder the portal shows, with completed orders laid
     * over it and orders in flight shown as pending.
     *
     * @param  list<object>  $orders
     * @return list<array{tier: string, state: string, establishedOn?: string|null, elapsed?: string|null}>
     */
    public function rungs(string $origin, string $status, Carbon $capturedAt, array $orders): array
    {
        $rungs = $this->tiers->rungs($origin, $status, $capturedAt);
        $index = array_flip(array_column($rungs, 'tier'));

        foreach ($orders as $order) {
            $tier = (string) $order->tier;

            if (! isset($index[$tier])) {
                continue;
            }

            $at = $index[$tier];

            if ($order->status === 'completed' && $order->completed_at !== null) {
                $rungs[$at] = ['tier' => $tier, ...$this->tiers->freshness(Carbon::parse((string) $order->completed_at))];
            } elseif (in_array($order->status, ['paid', 'assigned', 'in_progress', 'submitted'], true)
                && $rungs[$at]['state'] === 'not_established') {
                $rungs[$at] = ['tier' => $tier, 'state' => 'pending'];
            }
        }

        return array_values($rungs);
    }

    /**
     * @param  list<int>  $enterpriseIds
     * @return array<int, list<object>>
     */
    private function ordersFor(array $enterpriseIds): array
    {
        if ($enterpriseIds === []) {
            return [];
        }

        $rows = DB::select('
            SELECT enterprise_id, tier, status, completed_at, paid_at, created_at
            FROM verification_orders
            WHERE enterprise_id IN ('.implode(',', array_map('intval', array_unique($enterpriseIds))).')
            ORDER BY completed_at ASC NULLS LAST, created_at ASC
        ');

        $by = [];

        foreach ($rows as $row) {
            $by[(int) $row->enterprise_id][] = $row;
        }

        return $by;
    }

    /**
     * @param  list<object>  $orders
     * @return array<string, mixed>
     */
    private function project(object $row, array $orders): array
    {
        $captured = Carbon::parse((string) $row->captured_at);
        $rungs = $this->rungs((string) $row->origin, (string) $row->structure_status, $captured, $orders);

        $completed = array_values(array_filter($orders, static fn (object $o): bool => $o->status === 'completed'));
        $last = $completed === [] ? null : end($completed);
        $reverifying = array_filter(
            $orders,
            static fn (object $o): bool => in_array($o->status, ['paid', 'assigned', 'in_progress', 'submitted'], true),
        ) !== [];

        // The directory's own test for "verified", so a row here never
        // disagrees with the badge on the business's public page.
        $officerAttended = $this->tiers->forOrigin((string) $row->origin, (string) $row->structure_status) !== 'listed';

        $status = match (true) {
            $row->grant_status === 'granted' => ['key' => 'room', 'label' => 'Data room open'],
            $reverifying => ['key' => 'reverifying', 'label' => 'Re-verifying'],
            $officerAttended || $last !== null => ['key' => 'verified', 'label' => 'Verified'],
            default => ['key' => 'listed', 'label' => 'Listed'],
        };

        return [
            'id' => (int) $row->id,
            'enterpriseId' => (int) $row->enterprise_id,
            'name' => (string) $row->trading_name,
            'sector' => $row->sector === null ? null : (string) $row->sector,
            'sectorCode' => $row->sector_code === null ? null : (string) $row->sector_code,
            'activity' => $row->sector_class === null ? null : (string) $row->sector_class,
            'state' => $row->state === null ? null : (string) $row->state,
            'lga' => $row->lga === null ? null : (string) $row->lga,
            'score' => ($this->score)($rungs),
            'rungs' => $rungs,
            'since' => $row->operating_since === null ? null : (int) $row->operating_since,
            'seeking' => Seeking::from((string) $row->seeking)->label(),
            'seekingKey' => (string) $row->seeking,
            'status' => $status,
            'grant' => $row->grant_status === null ? null : (string) $row->grant_status,
            'verified' => $officerAttended || $last !== null,
            'lastVerified' => $last === null
                ? ($officerAttended ? $captured->toDateString() : null)
                : Carbon::parse((string) $last->completed_at)->toDateString(),
            'publishedAt' => $row->published_at === null ? null : Carbon::parse((string) $row->published_at)->toIso8601String(),
        ];
    }
}
