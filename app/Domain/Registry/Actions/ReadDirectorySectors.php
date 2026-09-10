<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use Illuminate\Support\Facades\DB;

/**
 * What the directory holds, counted by sector.
 *
 * Every number here is counted over exactly the rows the directory would show,
 * through DirectoryVisibility, and that is a disclosure decision rather than a
 * convenience. A count over the whole register would be a better statistic and
 * a worse thing to publish: it would describe businesses that chose not to be
 * here, and in a thinly populated ward a count of one is a name.
 *
 * The consequence is worth stating on the page rather than hiding: these
 * numbers describe the directory, not the economy. A sector where most
 * businesses have not claimed their listing looks small here and is not.
 *
 * "Active this month" counts businesses whose listing became visible during the
 * month, which is the only kind of movement this data can honestly report. It
 * is not trading activity, it is not growth, and it must never be labelled as
 * either.
 */
final class ReadDirectorySectors
{
    /**
     * Every sector with something in it, largest first.
     *
     * @return list<array{code: string, name: string, count: int, verified: int, joinedThisMonth: int}>
     */
    public function all(int $limit = 40): array
    {
        return array_map(static fn (object $row): array => [
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'count' => (int) $row->n,
            'verified' => (int) $row->verified,
            'joinedThisMonth' => (int) $row->joined,
        ], DB::select('
            SELECT
                isic.code AS code,
                isic.name AS name,
                count(*) AS n,
                count(*) FILTER (WHERE s.status = \'accepted\' AND s.origin = \'field\') AS verified,
                count(*) FILTER (WHERE e.captured_at >= date_trunc(\'month\', now())) AS joined
            '.DirectoryVisibility::FROM.'
            WHERE '.DirectoryVisibility::WHERE.'
              AND e.sector_code IS NOT NULL
            GROUP BY isic.code, isic.name
            ORDER BY n DESC, isic.name ASC
            LIMIT :limit
        ', ['limit' => $limit]));
    }

    /**
     * Businesses in the directory with no recorded trade.
     *
     * They exist: a business that registered itself and never picked a sector
     * is on the register and in the directory, and it belongs under no heading
     * on the sector page. Reported rather than dropped, because a reader who
     * counts the sectors and compares that to the directory total is owed the
     * difference instead of being left to wonder which number is wrong.
     */
    public function unclassified(): int
    {
        return (int) DB::selectOne('
            SELECT count(*) AS n
            '.DirectoryVisibility::FROM.'
            WHERE '.DirectoryVisibility::WHERE.'
              AND e.sector_code IS NULL
        ')->n;
    }

    /**
     * One sector, and where in it things are.
     *
     * The ward breakdown is the part worth care. A ward with one business in a
     * sector names that business to anybody who reads two pages, so wards
     * below the floor are folded into "elsewhere" rather than listed. This is
     * ordinary disclosure control and it costs nothing anybody wanted.
     *
     * A business whose ward never resolved goes to "elsewhere" too, rather than
     * being dropped. The first version filtered those out, which meant the
     * listed wards and the total silently disagreed and a reader had no way to
     * see why. Wards plus elsewhere equals the count, always.
     *
     * @return array{code: string, name: string, description: string|null, count: int, verified: int, wards: list<array{ward: string, count: int}>, elsewhere: int}|null
     */
    public function one(string $code): ?array
    {
        /** Below this a ward count identifies a business rather than describing a place. */
        $floor = 3;

        $sector = DB::selectOne(
            'SELECT code, name, description FROM isic_classes WHERE code = :code',
            ['code' => $code],
        );

        if ($sector === null) {
            return null;
        }

        $totals = DB::selectOne('
            SELECT
                count(*) AS n,
                count(*) FILTER (WHERE s.status = \'accepted\' AND s.origin = \'field\') AS verified
            '.DirectoryVisibility::FROM.'
            WHERE '.DirectoryVisibility::WHERE.'
              AND e.sector_code = :code
        ', ['code' => $code]);

        $wards = DB::select('
            SELECT ward.name AS ward, count(*) AS n
            '.DirectoryVisibility::FROM.'
            WHERE '.DirectoryVisibility::WHERE.'
              AND e.sector_code = :code
            GROUP BY ward.name
            ORDER BY n DESC NULLS LAST, ward.name ASC
        ', ['code' => $code]);

        $shown = [];
        $elsewhere = 0;

        foreach ($wards as $ward) {
            if ($ward->ward !== null && (int) $ward->n >= $floor) {
                $shown[] = ['ward' => (string) $ward->ward, 'count' => (int) $ward->n];

                continue;
            }

            $elsewhere += (int) $ward->n;
        }

        return [
            'code' => (string) $sector->code,
            'name' => (string) $sector->name,
            'description' => $sector->description === null ? null : (string) $sector->description,
            'count' => (int) $totals->n,
            'verified' => (int) $totals->verified,
            'wards' => $shown,
            'elsewhere' => $elsewhere,
        ];
    }
}
