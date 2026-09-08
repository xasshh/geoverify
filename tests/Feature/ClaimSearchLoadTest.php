<?php

declare(strict_types=1);

use App\Domain\Claim\Actions\SearchRegister;
use App\Domain\Coverage\Models\GridCell;
use App\Enums\Role;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Claim search under load
|--------------------------------------------------------------------------
|
| The M8 gate, and the one query in this system a stranger can run. Claim
| search is reachable by anybody who has proved nothing, which makes it both
| the most exposed statement here and the one most worth making slow on
| purpose if you wanted to hurt us.
|
| So this seeds a register rather than a fixture and measures. The rows go in
| through SQL rather than through the capture flow: what is being measured is
| the read, and building fifty thousand shops through the action that writes
| them would spend ten minutes proving something SyncLoadTest already proves.
|
*/

/** How many businesses to search across. Roughly one large LGA. */
const REGISTER_SIZE = 50_000;

/**
 * A register, generated in the database.
 *
 * Names are drawn at random from three vocabularies rather than from the row
 * id. The first attempt at this keyed every word off the id, which produced
 * forty name shapes across fifty thousand rows: a search then matched a fifth
 * of the table, PostgreSQL correctly refused the index for a sequential scan,
 * and the whole thing measured a register that does not exist. Real trading
 * names collide sometimes and repeat rarely, which is what this generates.
 *
 * @return string one trading name that is actually in there, to search for
 */
function seedRegister(int $count): string
{
    $officer = person(Role::Officer, 'Load Officer');
    $cell = assignedCell($officer, person(Role::Supervisor, 'Load Supervisor'));

    /** @var GridCell $cell */
    $areaId = $cell->coverage_area_id;

    DB::statement(<<<'SQL'
        INSERT INTO structures (
            grid_cell_id, coverage_area_id, h3_index, captured_by, captured_at,
            structure_type, status, origin, client_uuid, centroid, created_at, updated_at
        )
        SELECT
            :cell, :area, :h3, :officer, now(),
            'shophouse', 'submitted', 'field', gen_random_uuid(),
            ST_SetSRID(ST_MakePoint(
                7.46 + ((g % 220) * 0.00018),
                9.05 + ((g / 220) * 0.00018)
            ), 4326)::geography,
            now(), now()
        FROM generate_series(1, :count) g
    SQL, [
        'cell' => $cell->id,
        'area' => $areaId,
        'h3' => $cell->h3_index,
        'officer' => $officer->id,
        'count' => $count,
    ]);

    DB::statement(<<<'SQL'
        INSERT INTO enterprises (
            structure_id, captured_by, captured_at, trading_name, sector_code,
            status, origin, publication_state, client_uuid, created_at, updated_at
        )
        SELECT
            s.id, :officer, now(),
            (ARRAY[
                'Mama','Alhaji','Chief','Sister','Bros','Royal','Golden','City',
                'Divine','Sunrise','Unity','Peace','Victory','Emerald','Crown','Rock',
                'Bright','Faithful','Anointed','Precious','Silverline','Marvellous','Zenith','Pillar'
            ])[1 + floor(random() * 24)::int]
            || ' ' ||
            (ARRAY[
                'Blessing','Grace','Chidinma','Aisha','Ngozi','Emeka','Funmi','Ibrahim',
                'Chukwu','Halima','Oluwaseun','Musa','Adaeze','Yusuf','Chinelo','Bola',
                'Kelechi','Zainab','Obinna','Amaka'
            ])[1 + floor(random() * 20)::int]
            || ' ' ||
            (ARRAY[
                'Stores','Provisions','Ventures','Enterprises','Pharmacy','Boutique',
                'Motors','Bakery','Electronics','Fabrics','Cold Room','Barbing Salon',
                'Cyber Cafe','Poultry','Plumbing','Investments'
            ])[1 + floor(random() * 16)::int],
            '4711', 'submitted', 'field', 'private', gen_random_uuid(), now(), now()
        FROM structures s
        WHERE s.captured_by = :officer2
    SQL, ['officer' => $officer->id, 'officer2' => $officer->id]);

    // One observation each, because the search reaches for the latest one
    // through a LATERAL join to decide whether a phone was ever recorded. A
    // table with no observations would measure a join that never finds a row.
    DB::statement(<<<'SQL'
        INSERT INTO enterprise_observations (
            enterprise_id, captured_by, observed_at, trading_name, sector_code,
            operating_status, phone, signage_observed, status, client_uuid,
            created_at, updated_at
        )
        SELECT
            e.id, :officer, now() - interval '30 days', e.trading_name, '4711',
            'operating',
            CASE WHEN e.id % 3 = 0 THEN NULL ELSE '0803 123 4567' END,
            (e.id % 2 = 0), 'submitted', gen_random_uuid(), now(), now()
        FROM enterprises e
        WHERE e.captured_by = :officer2
    SQL, ['officer' => $officer->id, 'officer2' => $officer->id]);

    DB::statement('ANALYZE structures');
    DB::statement('ANALYZE enterprises');
    DB::statement('ANALYZE enterprise_observations');

    // A name that is genuinely in there, because the person searching is
    // typing the name of their own shop rather than a term we invented.
    return (string) DB::table('enterprises')->orderBy('id')->value('trading_name');
}

it('searches fifty thousand businesses by name within a time budget', function () {
    $name = seedRegister(REGISTER_SIZE);

    $search = app(SearchRegister::class);

    // Warm, then measured. The first call of the process pays for the
    // connection and the plan cache, which is a cost the tenth searcher of the
    // morning does not pay and should not be charged to this number.
    $search->run($name);

    $measurement = measured(fn (): array => $search->run($name));

    dump([
        'rows' => REGISTER_SIZE,
        'seconds' => $measurement['seconds'],
        'queries' => $measurement['queries'],
        'results' => count($measurement['result']),
    ]);

    expect($measurement['result'])->not->toBeEmpty()
        // Two statements: the session's similarity floor, and the search. The
        // number must not grow with the size of the register, and a LATERAL
        // join that became a loop in PHP would show up here first.
        ->and($measurement['queries'])->toBe(2)
        ->and($measurement['seconds'])->toBeLessThan(1.0);
})->group('load');

it('searches by proximity alone within a time budget', function () {
    seedRegister(REGISTER_SIZE);

    $search = app(SearchRegister::class);
    $search->run('', 9.05, 7.46);

    $measurement = measured(fn (): array => $search->run('', 9.0505, 7.4605));

    dump([
        'rows' => REGISTER_SIZE,
        'seconds' => $measurement['seconds'],
        'results' => count($measurement['result']),
    ]);

    expect($measurement['result'])->not->toBeEmpty()
        ->and($measurement['seconds'])->toBeLessThan(1.0);
})->group('load');

/** The plan PostgreSQL chooses for one term, as text. */
function explainFor(string $term): string
{
    /** @var list<object{'QUERY PLAN': string}> $plan */
    $plan = DB::select(
        'EXPLAIN (ANALYZE, BUFFERS) SELECT e.id FROM enterprises e WHERE e.trading_name % ?',
        [$term],
    );

    return implode("\n", array_map(static fn (object $row): string => (string) $row->{'QUERY PLAN'}, $plan));
}

it('answers a distinctive name from the trigram index rather than by reading the table', function () {
    $common = seedRegister(REGISTER_SIZE);

    // A shop whose name shares no words with anything else in the register,
    // which is what a search for your own business usually is.
    DB::table('enterprises')
        ->whereIn('id', fn ($q) => $q->from('enterprises')->select('id')->orderBy('id')->limit(1))
        ->update(['trading_name' => 'Zamfara Quartzite Holdings']);

    DB::statement('ANALYZE enterprises');
    DB::statement('SELECT set_limit(0.18)');

    $selective = explainFor('Zamfara Quartzite Holdings');

    dump(['selective_plan' => $selective]);

    // The docblock on SearchRegister says the `%` operator is what makes this
    // index reachable at all, and that similarity() >= 0.18 would not be. This
    // is the assertion behind that claim.
    expect($selective)->toContain('enterprises_trading_name_trgm')
        ->and($selective)->not->toContain('Seq Scan on enterprises');
})->group('load');

it('reads the table for a name built from words every shop uses, and still answers', function () {
    $common = seedRegister(REGISTER_SIZE);

    DB::statement('SELECT set_limit(0.18)');

    $plan = explainFor($common);
    $matches = (int) DB::table('enterprises')->whereRaw('trading_name % ?', [$common])->count();

    $search = app(SearchRegister::class);
    $search->run($common);
    $measurement = measured(fn (): array => $search->run($common));

    dump([
        'term' => $common,
        'matched_rows' => $matches,
        'of' => REGISTER_SIZE,
        'seconds' => $measurement['seconds'],
        'indexed' => str_contains($plan, 'enterprises_trading_name_trgm'),
    ]);

    // This is the half the first version of this test got wrong. Nigerian
    // trading names share their last word constantly: Stores, Ventures,
    // Enterprises. A search for one of those matches a large slice of the
    // register at a 0.18 floor, and PostgreSQL is right to read the table
    // rather than the index for it. Asserting an index scan here would be
    // asserting that the planner is wrong.
    //
    // What must hold is the answer, not the plan. A stranger can reach this
    // query, so the unselective case is the one worth a budget.
    expect($matches)->toBeGreaterThan(1_000)
        ->and($measurement['seconds'])->toBeLessThan(2.0)
        ->and($measurement['result'])->not->toBeEmpty()
        // Twenty five, however many matched. The projection caps what leaves,
        // which is what stops a common word being a bulk export.
        ->and(count($measurement['result']))->toBeLessThanOrEqual(25);
})->group('load');
