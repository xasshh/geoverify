<?php

declare(strict_types=1);

namespace App\Domain\Verification\Exports;

use App\Domain\Identity\Models\ProcessingPurpose;
use App\Domain\Registry\Actions\ResolveListingTier;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\Structure;
use App\Domain\Verification\Actions\ResolvePublicVerification;
use App\Domain\Verification\Models\PublicVerification;
use App\Domain\Verification\Models\VerificationOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Everything the certificate prints, gathered once.
 *
 * The document says what was found, where, when, by what accuracy, and on whose
 * authority, and every one of those has to come from the record rather than
 * from the order. An order says what was bought; the visit says what happened,
 * and a certificate that quoted the purchase would be a receipt dressed as
 * evidence.
 *
 * Coordinates come out of PostGIS rather than being read off a column, because
 * the point is stored as geography and the certificate needs decimal degrees.
 * That is one query, not haversine in PHP.
 */
final class AssembleCertificate
{
    /** Past this a photograph is not worth the size it adds to the document. */
    private const MAX_EMBEDDED_BYTES = 2_500_000;

    public function __construct(
        private readonly ResolveListingTier $tiers,
        private readonly ResolvePublicVerification $resolve,
        private readonly QrCode $qr,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(VerificationOrder $order): array
    {
        $order->loadMissing([
            'enterprise.structure.ward',
            'enterprise.structure.lga',
            'enterprise.structure.state',
        ]);

        $enterprise = $order->enterprise;
        $structure = $enterprise->structure;

        $verification = PublicVerification::query()
            ->where('verification_order_id', $order->id)
            ->whereNull('revoked_at')
            ->first();

        if (! $verification instanceof PublicVerification) {
            throw new RuntimeException('A certificate exists only for a completed verification.');
        }

        $point = $this->position($structure->id);

        $establishedOn = $order->completed_at ?? $verification->issued_at;
        $freshness = $this->tiers->freshness($establishedOn);

        $purpose = ProcessingPurpose::named(ProcessingPurpose::VERIFICATION);

        return [
            'reference' => $order->reference,
            'issued_on' => $verification->issued_at->format('j F Y'),

            'business' => [
                'name' => $enterprise->trading_name,
                'registered_name' => $enterprise->registered_name,
                'sector' => $enterprise->sector_code,
                'unit' => $enterprise->unit_label,
            ],

            'place' => [
                'ward' => $structure->ward?->name,
                'lga' => $structure->lga?->name,
                'state' => $structure->state?->name,
                'plus_code' => $structure->plus_code,
                'latitude' => $point['lat'],
                'longitude' => $point['lon'],
                'accuracy_m' => $structure->capture_accuracy_m,
                'structure_type' => $structure->structure_type,
            ],

            'finding' => [
                'outcome' => $order->outcome?->label() ?? 'Recorded',
                'establishes_tier' => $order->outcome?->establishesTier() ?? false,
                'tier' => $order->tier,
                'visited_on' => $establishedOn->format('j F Y'),
                'freshness' => $freshness['state'],
                'elapsed' => $freshness['elapsed'],
            ],

            // Who attended, without saying who they are. The same handle the
            // public page shows, so a support conversation about one is a
            // conversation about the other.
            'officer_reference' => ($this->resolve)($verification->token)['officer_reference'] ?? 'GV-0000',

            'photographs' => $this->photographs($enterprise->id, $structure->id),

            'check' => [
                'url' => route('verify.show', ['token' => $verification->token]),
                'token' => $verification->token,
                'valid_until' => $verification->valid_until?->format('j F Y'),
                'qr' => $this->qr->svg(route('verify.show', ['token' => $verification->token]), 150),
            ],

            'basis' => [
                'lawful_basis' => $purpose->lawful_basis,
                'disclosure_version' => $purpose->disclosure_version,
            ],
        ];
    }

    /**
     * The captured position, in degrees.
     *
     * @return array{lat: float|null, lon: float|null}
     */
    private function position(int $structureId): array
    {
        $row = DB::selectOne(
            'select st_y(centroid::geometry) as lat, st_x(centroid::geometry) as lon
             from structures where id = ?',
            [$structureId],
        );

        return [
            'lat' => $row?->lat === null ? null : (float) $row->lat,
            'lon' => $row?->lon === null ? null : (float) $row->lon,
        ];
    }

    /**
     * The photographs an officer took, as data URIs.
     *
     * Embedded rather than linked because the printing browser fetches this
     * page over the loopback interface with no session, and a signed media URL
     * that expires in ten minutes is one more thing that can be the reason a
     * certificate prints with holes in it.
     *
     * The thumbnail is preferred over the original for the same reason the
     * evidence pack prefers it: a certificate is a document somebody prints,
     * not an archive of full frame photographs.
     *
     * @return list<array{kind: string, taken_on: string, src: string}>
     */
    private function photographs(int $enterpriseId, int $structureId): array
    {
        $rows = DB::select(<<<'SQL'
            select kind, disk, disk_path, thumb_path, captured_at
            from media
            where kind in ('facade', 'signage', 'street_context')
              and ((mediable_type = ? and mediable_id = ?)
                or (mediable_type = ? and mediable_id = ?))
            order by captured_at, id
            limit 4
        SQL, [
            (new Enterprise)->getMorphClass(), $enterpriseId,
            (new Structure)->getMorphClass(), $structureId,
        ]);

        $out = [];

        foreach ($rows as $row) {
            $src = $this->embed($row);

            if ($src === null) {
                continue;
            }

            $out[] = [
                'kind' => (string) $row->kind,
                'taken_on' => $row->captured_at === null
                    ? ''
                    : Carbon::parse((string) $row->captured_at)->format('j F Y'),
                'src' => $src,
            ];
        }

        return $out;
    }

    /** The smallest usable rendition, or nothing at all. */
    private function embed(object $row): ?string
    {
        $disk = Storage::disk((string) ($row->disk ?: 'media'));

        foreach ([$row->thumb_path ?? null, $row->disk_path ?? null] as $path) {
            if (! is_string($path) || $path === '' || ! $disk->exists($path)) {
                continue;
            }

            if ($disk->size($path) > self::MAX_EMBEDDED_BYTES) {
                continue;
            }

            $mime = $disk->mimeType($path) ?: 'image/jpeg';

            return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($path));
        }

        return null;
    }
}
