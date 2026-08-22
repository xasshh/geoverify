<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enables the extensions the register depends on.
 *
 * These are created here rather than left to the database image so that a fresh
 * checkout is provably runnable, and so that a missing extension fails at migrate
 * time with a readable message instead of failing later inside a grid generation.
 */
return new class extends Migration
{
    /**
     * postgis     spatial types, indexes and predicates. All spatial work runs here.
     * h3          hexagonal grid indexing. Supplies h3_polygon_to_cells for grid generation.
     * h3_postgis  the bridge between the two: cell to geometry, geometry to cell.
     * pg_trgm     trigram similarity, used for duplicate trading name detection.
     * pgcrypto    digest() for reference token hashing on identity claims.
     *
     * @var list<string>
     */
    private const EXTENSIONS = ['postgis', 'h3', 'h3_postgis', 'pg_trgm', 'pgcrypto'];

    public function up(): void
    {
        foreach (self::EXTENSIONS as $extension) {
            // CASCADE lets h3_postgis pull in its own requirements on a clean database.
            DB::statement("CREATE EXTENSION IF NOT EXISTS \"{$extension}\" CASCADE");
        }

        $this->assertGridGenerationWorks();
    }

    public function down(): void
    {
        // Extensions are shared database state. Dropping postgis would take every
        // spatial column with it, so this migration is deliberately not reversible.
    }

    /**
     * Proves the H3 and PostGIS bridge actually functions, rather than trusting that
     * CREATE EXTENSION succeeding means the bindings are usable.
     */
    private function assertGridGenerationWorks(): void
    {
        $cells = DB::scalar(
            "select count(*) from h3_polygon_to_cells(
                st_geomfromtext('POLYGON((7.44 9.03, 7.50 9.03, 7.50 9.08, 7.44 9.08, 7.44 9.03))', 4326), 9
            )",
        );

        if (! is_numeric($cells) || (int) $cells < 1) {
            throw new RuntimeException(
                'H3 grid generation returned no cells. The h3 and h3_postgis extensions are '
                .'installed but not working. Check that the h3-pg build matches this PostgreSQL version.',
            );
        }
    }
};
