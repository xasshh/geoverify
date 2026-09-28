<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

/**
 * One definition of what the public directory can see.
 *
 * Extracted the moment a second query needed it. Sector pages count businesses,
 * and a count computed from a slightly different predicate than the list is how
 * a page says "14 pharmacies" over a list of nine, or worse, counts a business
 * that asked not to be here. The rows and the numbers about the rows have to
 * come from the same WHERE or they will drift, quietly, in the direction of
 * disclosing more.
 *
 * Composed as SQL fragments rather than a query builder because the directory's
 * projection is deliberately hand written: the columns that leave this system
 * are named one by one in SearchDirectory, and a builder that could be extended
 * with a scope somewhere else is exactly the thing that must not exist here.
 */
final class DirectoryVisibility
{
    /**
     * The joins every directory query needs, including the lateral that reads
     * the latest observation. `latest.signage_observed` is what decides whether
     * an unclaimed business is here at all.
     */
    public const FROM = <<<'SQL'
        FROM enterprises e
        JOIN structures s ON s.id = e.structure_id
        LEFT JOIN admin_boundaries ward ON ward.id = s.ward_id
        LEFT JOIN admin_boundaries lga  ON lga.id  = s.lga_id
        LEFT JOIN isic_classes isic ON isic.code = e.sector_code
        LEFT JOIN party_businesses pb
               ON pb.enterprise_id = e.id AND pb.status = 'active'
        LEFT JOIN business_profiles bp ON bp.enterprise_id = e.id
        LEFT JOIN LATERAL (
            SELECT o.opening_hours, o.signage_observed
            FROM enterprise_observations o
            WHERE o.enterprise_id = e.id
            ORDER BY o.observed_at DESC
            LIMIT 1
        ) latest ON TRUE
        SQL;

    /**
     * Who may be seen, and there is no branch past this.
     *
     * A rejected capture is not a business. A withheld record is absent
     * whatever else is true of it, checked before anything else. Beyond that:
     * claimed and opted in, or unclaimed with its name on the street.
     */
    public const WHERE = <<<'SQL'
        s.status <> 'rejected'
          AND e.publication_state <> 'withheld'
          AND (
                (pb.id IS NOT NULL AND e.publication_state = 'opted_in')
             OR (pb.id IS NULL AND latest.signage_observed IS TRUE)
          )
        SQL;
}
