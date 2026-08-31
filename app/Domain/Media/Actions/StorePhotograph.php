<?php

declare(strict_types=1);

namespace App\Domain\Media\Actions;

use App\Domain\Media\Models\Media;
use App\Domain\Registry\Models\Structure;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Stores a photograph and records what can be checked about it later.
 *
 * Three positions are kept apart on purpose: where the file's own metadata says
 * the camera was, where the device claimed to be at that moment, and where the
 * subject stands. Agreement between them is evidence. Disagreement is the
 * finding, and overwriting one with another would erase it.
 *
 * Nothing here rejects a photograph. A facade shot from four hundred metres away
 * is recorded with its distance so a supervisor can see it, because an officer
 * photographing across a busy road is a real situation and a hard rule would
 * throw away good work along with bad.
 */
final class StorePhotograph
{
    /** Photographs above this are refused: the client compresses before sending. */
    private const MAX_BYTES = 4 * 1024 * 1024;

    public function __construct(private readonly StoreMediaFile $files) {}

    public function store(
        UploadedFile $file,
        Model $subject,
        string $kind,
        User $officer,
        string $clientUuid,
        ?float $deviceLongitude = null,
        ?float $deviceLatitude = null,
        ?int $fieldSessionId = null,
    ): Media {
        $exif = $this->readExif($file);
        $size = @getimagesize($file->getRealPath() ?: '');

        // The file handling, the digest and the row are shared with a party's
        // document upload. What stays here is what only a photograph has: the
        // camera metadata, the positions, and an officer standing behind it.
        $media = $this->files->put($file, $subject, $kind, $clientUuid, [
            'width' => $size === false ? null : $size[0],
            'height' => $size === false ? null : $size[1],
            'captured_at' => now(),
            'exif' => $exif['data'],
            // False when the file carries no camera metadata at all, which is
            // what a screenshot or a downloaded image looks like.
            'from_device_camera' => $exif['fromCamera'],
            'captured_by' => $officer->id,
            'field_session_id' => $fieldSessionId,
        ], self::MAX_BYTES);

        // A retried upload comes back already positioned and already logged.
        if ($media->wasRecentlyCreated === false) {
            return $media;
        }

        $this->writePositions($media, $exif, $deviceLongitude, $deviceLatitude, $subject);

        $media->refresh();

        VerificationEvent::record($media, 'media.stored', $officer, [
            'kind' => $kind,
            'sha256' => $media->sha256,
            'bytes' => $file->getSize(),
            'from_device_camera' => $exif['fromCamera'],
            'distance_from_subject_m' => $media->distance_from_subject_m,
            'subject' => $subject->getMorphClass().'#'.(string) $subject->getKey(),
        ]);

        return $media;
    }

    /**
     * Positions and the distance between camera and subject, all in PostGIS.
     *
     * @param  array{data: array<string, mixed>|null, lon: float|null, lat: float|null, fromCamera: bool}  $exif
     */
    private function writePositions(
        Media $media,
        array $exif,
        ?float $deviceLongitude,
        ?float $deviceLatitude,
        Model $subject,
    ): void {
        $subjectId = $subject instanceof Structure ? $subject->id : null;

        DB::statement(<<<'SQL'
            UPDATE media m
               SET capture_point = CASE WHEN ?::float IS NULL THEN NULL
                                        ELSE ST_SetSRID(ST_Point(?, ?), 4326)::geography END,
                   device_reported_point = CASE WHEN ?::float IS NULL THEN NULL
                                                ELSE ST_SetSRID(ST_Point(?, ?), 4326)::geography END,
                   distance_from_subject_m = (
                       SELECT round(ST_Distance(
                           COALESCE(
                               CASE WHEN ?::float IS NULL THEN NULL
                                    ELSE ST_SetSRID(ST_Point(?, ?), 4326)::geography END,
                               CASE WHEN ?::float IS NULL THEN NULL
                                    ELSE ST_SetSRID(ST_Point(?, ?), 4326)::geography END
                           ),
                           s.centroid
                       )::numeric, 2)
                         FROM structures s WHERE s.id = ?
                   ),
                   updated_at = now()
             WHERE m.id = ?
        SQL, [
            $exif['lon'], $exif['lon'], $exif['lat'],
            $deviceLongitude, $deviceLongitude, $deviceLatitude,
            $exif['lon'], $exif['lon'], $exif['lat'],
            $deviceLongitude, $deviceLongitude, $deviceLatitude,
            $subjectId,
            $media->id,
        ]);
    }

    /**
     * @return array{data: array<string, mixed>|null, lon: float|null, lat: float|null, fromCamera: bool}
     */
    private function readExif(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        if ($path === false || ! function_exists('exif_read_data')) {
            return ['data' => null, 'lon' => null, 'lat' => null, 'fromCamera' => false];
        }

        /** @var array<string, mixed>|false $raw */
        $raw = @exif_read_data($path);

        if ($raw === false) {
            // No metadata at all. A phone camera writes some; a screenshot or a
            // downloaded image usually writes none.
            return ['data' => null, 'lon' => null, 'lat' => null, 'fromCamera' => false];
        }

        $fromCamera = isset($raw['Make']) || isset($raw['Model']);

        return [
            'data' => array_intersect_key($raw, array_flip([
                'Make', 'Model', 'DateTimeOriginal', 'Orientation', 'ExposureTime', 'ISOSpeedRatings',
            ])),
            'lon' => $this->coordinate($raw, 'GPSLongitude', 'GPSLongitudeRef', 'W'),
            'lat' => $this->coordinate($raw, 'GPSLatitude', 'GPSLatitudeRef', 'S'),
            'fromCamera' => $fromCamera,
        ];
    }

    /**
     * @param  array<string, mixed>  $exif
     */
    private function coordinate(array $exif, string $key, string $refKey, string $negativeRef): ?float
    {
        $parts = $exif[$key] ?? null;

        if (! is_array($parts) || count($parts) < 3) {
            return null;
        }

        $toDecimal = static function (mixed $fraction): float {
            if (! is_string($fraction)) {
                return is_numeric($fraction) ? (float) $fraction : 0.0;
            }

            [$numerator, $denominator] = array_pad(explode('/', $fraction), 2, '1');

            return (float) $denominator === 0.0 ? 0.0 : (float) $numerator / (float) $denominator;
        };

        $degrees = $toDecimal($parts[0]) + $toDecimal($parts[1]) / 60 + $toDecimal($parts[2]) / 3600;
        $ref = is_string($exif[$refKey] ?? null) ? $exif[$refKey] : '';

        return $ref === $negativeRef ? -$degrees : $degrees;
    }
}
