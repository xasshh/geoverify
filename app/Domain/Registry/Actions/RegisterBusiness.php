<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Party\Models\Party;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\Structure;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A business puts itself on the register.
 *
 * The other way in, and the one with no officer behind it. What comes out is
 * deliberately weaker than a field capture and says so in the data: origin is
 * self_registered, the structure is unconfirmed, and the ladder stops at
 * listed. It is a real record that a real person is accountable for, and it is
 * not evidence that anybody went and looked.
 *
 * The location is not optional and cannot be skipped, because the entire
 * platform rests on the rule that an entity without a verifiable location does
 * not appear. A business that cannot say where it is has nothing for an officer
 * to visit, and so can never climb past listed however much it wants to pay.
 */
final class RegisterBusiness
{
    /** The resolution the field grid uses, so the two are comparable. */
    private const H3_RESOLUTION = 9;

    public function __construct(
        private readonly ResolveAdminHierarchy $hierarchy,
        private readonly DetectDuplicateEnterprise $duplicates,
    ) {}

    /**
     * @param  array{trading_name: string, sector_code: string|null, structure_type: string, longitude: float, latitude: float, external_footprint_id: int|null, phone: string|null, accuracy_m: float|null}  $input
     */
    public function register(Party $party, array $input): Enterprise
    {
        // Resolved here, from the point, every time. Nothing the browser sent
        // about where this is has any standing: a ward is a legal fact about a
        // coordinate, not an opinion the person filling in the form holds.
        $resolved = $this->hierarchy->forPoint($input['longitude'], $input['latitude']);

        if ($resolved['state_id'] === null) {
            throw new RuntimeException(
                'That location is outside the areas we cover. We cannot list a business we could never visit.'
            );
        }

        return DB::transaction(function () use ($party, $input, $resolved): Enterprise {
            $structureId = $this->createStructure($party, $input, $resolved);
            $enterprise = $this->createEnterprise($party, $structureId, $input);

            // Control is immediate and needs no claim behind it. The party did
            // not find this listing and assert it was theirs: they created it,
            // and there is nobody else it could belong to.
            PartyBusiness::query()->create([
                'party_id' => $party->id,
                'enterprise_id' => $enterprise->id,
                'relationship' => 'owner',
                'established_via' => PartyBusiness::VIA_SELF_REGISTRATION,
                'established_at' => now(),
                'status' => PartyBusiness::STATUS_ACTIVE,
            ]);

            VerificationEvent::recordForParty($enterprise, 'business.self_registered', $party, [
                'structure_id' => $structureId,
                // The hierarchy we resolved, recorded next to the act, so a
                // later argument about which ward this is in can be settled by
                // reading what the server decided rather than by re-deriving it
                // from boundaries that may since have been revised.
                'ward_id' => $resolved['ward_id'],
                'lga_id' => $resolved['lga_id'],
                'state_id' => $resolved['state_id'],
                'from_footprint' => $input['external_footprint_id'] !== null,
            ]);

            return $enterprise;
        });
    }

    /**
     * Businesses already on the register that this one may be.
     *
     * Run before anything is written, so the answer is an offer rather than a
     * flag on a record that now exists. Same rule the field platform applies
     * after a capture: see DetectDuplicateEnterprise.
     *
     * @return list<array{enterprise_id: int, trading_name: string, distance_m: float, similarity: float}>
     */
    public function possibleDuplicates(float $longitude, float $latitude, string $tradingName): array
    {
        return $this->duplicates->nearPoint($longitude, $latitude, $tradingName);
    }

    /**
     * @param  array{trading_name: string, sector_code: string|null, structure_type: string, longitude: float, latitude: float, external_footprint_id: int|null, phone: string|null, accuracy_m: float|null}  $input
     * @param  array{ward_id: int|null, lga_id: int|null, state_id: int|null}  $resolved
     */
    private function createStructure(Party $party, array $input, array $resolved): int
    {
        // No cell, and deliberately none: cell progress is a count of
        // structures in the cell, and a self-registration holding one would
        // inflate the coverage percentage a supervisor reads as officer work.
        // The h3 index comes from the point instead, at the same resolution the
        // field grid uses, so the two can still be compared spatially.
        // The point is decided once, in a CTE, and everything else reads it.
        // Written out four times as a repeated expression it was four chances
        // for the centroid, the h3 index and the footprint link to disagree
        // about where this building is.
        $id = DB::scalar(<<<'SQL'
            WITH place AS (
                SELECT COALESCE(
                    -- The chosen building's own centre when there is one, and
                    -- the person's position only when there is not. Somebody
                    -- across the road who correctly identifies their own shop
                    -- should not leave the register pointing at the road.
                    (SELECT ST_Centroid(f.footprint) FROM external_footprints f WHERE f.id = ?::bigint),
                    ST_SetSRID(ST_Point(?, ?), 4326)
                ) AS geom
            )
            INSERT INTO structures (
                grid_cell_id, coverage_area_id, external_footprint_id,
                ward_id, lga_id, state_id, h3_index,
                registered_by_party_id, origin, captured_at, capture_accuracy_m,
                structure_type, occupancy_status, status, client_uuid,
                centroid, footprint, created_at, updated_at
            )
            SELECT
                NULL, NULL, ?::bigint,
                ?, ?, ?,
                h3_lat_lng_to_cell(place.geom, ?)::bigint,
                ?, ?, now(), ?,
                ?, 'occupied', ?, ?,
                place.geom::geography,
                (SELECT f.footprint FROM external_footprints f WHERE f.id = ?::bigint),
                now(), now()
            FROM place
            RETURNING id
        SQL, [
            $input['external_footprint_id'],
            $input['longitude'],
            $input['latitude'],
            $input['external_footprint_id'],
            $resolved['ward_id'],
            $resolved['lga_id'],
            $resolved['state_id'],
            self::H3_RESOLUTION,
            $party->id,
            Structure::ORIGIN_SELF_REGISTERED,
            $input['accuracy_m'],
            $input['structure_type'],
            Structure::STATUS_UNCONFIRMED,
            (string) Str::uuid7(),
            $input['external_footprint_id'],
        ]);

        if ($id === null) {
            throw new RuntimeException('The business could not be registered.');
        }

        // Claimed, so the same footprint cannot back two listings. Done inside
        // the transaction that created the structure, so a footprint is never
        // marked used by a registration that then failed.
        if ($input['external_footprint_id'] !== null) {
            DB::update(
                'UPDATE external_footprints SET matched_structure_id = ?, updated_at = now()
                  WHERE id = ? AND matched_structure_id IS NULL',
                [$id, $input['external_footprint_id']],
            );
        }

        return (int) $id;
    }

    /**
     * @param  array{trading_name: string, sector_code: string|null, structure_type: string, longitude: float, latitude: float, external_footprint_id: int|null, phone: string|null, accuracy_m: float|null}  $input
     */
    private function createEnterprise(Party $party, int $structureId, array $input): Enterprise
    {
        $enterprise = Enterprise::query()->create([
            'structure_id' => $structureId,
            'trading_name' => $input['trading_name'],
            'sector_code' => $input['sector_code'],
            'operating_status' => 'operating',
            'origin' => Enterprise::ORIGIN_SELF_REGISTERED,
            'registered_by_party_id' => $party->id,
            'captured_at' => now(),
            'status' => 'submitted',
            'client_uuid' => (string) Str::uuid7(),
        ]);

        // The same observation shape a field capture writes, so everything
        // downstream reads one thing. What differs is the author, named on the
        // row: signage_observed is false because nobody looked at any signage,
        // not because there is none.
        DB::insert(<<<'SQL'
            INSERT INTO enterprise_observations (
                enterprise_id, recorded_by_party_id, observed_at, trading_name,
                sector_code, operating_status, phone, signage_observed, status,
                client_uuid, created_at, updated_at
            )
            VALUES (?, ?, now(), ?, ?, 'operating', ?, false, 'submitted', ?, now(), now())
        SQL, [
            $enterprise->id,
            $party->id,
            $input['trading_name'],
            $input['sector_code'],
            $input['phone'],
            (string) Str::uuid7(),
        ]);

        return $enterprise;
    }
}
