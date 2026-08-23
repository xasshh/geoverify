<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use Illuminate\Support\Facades\DB;

/**
 * The sector picker an officer actually uses.
 *
 * They type "vulcanizer" or "mai shayi", not "4520". Aliases are searched first
 * and rank above ISIC's own wording, because a picker that only accepts UN
 * phrasing gets the sector wrong six hundred times a week.
 *
 * Trigram similarity rather than prefix matching, so a misspelling still lands.
 */
final class SearchSectors
{
    /**
     * @return list<array{code: string, name: string, matchedOn: string, isAlias: bool}>
     */
    public function search(string $term, int $limit = 12): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        /** @var list<object{code: string, name: string, matched_on: string, is_alias: bool}> $rows */
        $rows = DB::select(<<<'SQL'
            WITH matches AS (
                SELECT a.isic_code       AS code,
                       a.term            AS matched_on,
                       true              AS is_alias,
                       -- Alias weight lifts the terms officers reach for above a
                       -- coincidental similarity in ISIC's own wording.
                       similarity(a.term, ?) * (a.weight::float / 100) AS score
                  FROM trade_aliases a
                 WHERE a.term % ?

                UNION ALL

                SELECT c.code, c.name, false, similarity(c.name, ?) * 0.8
                  FROM isic_classes c
                 WHERE c.level = 'class' AND c.name % ?
            )
            SELECT m.code, c.name, m.matched_on, m.is_alias
              FROM matches m
              JOIN isic_classes c ON c.code = m.code
             ORDER BY m.score DESC, c.code
             LIMIT ?
        SQL, [$term, $term, $term, $term, $limit]);

        return array_map(static fn (object $r): array => [
            'code' => $r->code,
            'name' => $r->name,
            'matchedOn' => $r->matched_on,
            'isAlias' => (bool) $r->is_alias,
        ], $rows);
    }

    /**
     * The division a class belongs to, which is what gets reported at the level
     * an economist reads.
     */
    public function divisionFor(string $classCode): ?string
    {
        $division = substr($classCode, 0, 2);

        $exists = DB::scalar(
            "select 1 from isic_classes where code = ? and level = 'division'",
            [$division],
        );

        return $exists === null ? null : $division;
    }
}
