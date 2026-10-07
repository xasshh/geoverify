<?php

declare(strict_types=1);

namespace App\Domain\Imagery;

use RuntimeException;

/**
 * Reads the fixed 127 byte header of a PMTiles v3 archive.
 *
 * The zoom range and bounds an officer's map is told come from the archive
 * itself, not from what the pipeline meant to build: if GDAL chose a different
 * zoom than expected, the phone must be told the truth.
 */
final class PmtilesHeader
{
    public const TILE_TYPE_PNG = 2;

    public const TILE_TYPE_JPEG = 3;

    public const TILE_TYPE_WEBP = 4;

    /**
     * @return array{min_zoom: int, max_zoom: int, west: float, south: float, east: float, north: float, tile_type: int}
     */
    public static function read(string $path): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The imagery archive could not be opened.');
        }

        $bytes = (string) fread($handle, 127);
        fclose($handle);

        if (strlen($bytes) < 127 || substr($bytes, 0, 7) !== 'PMTiles' || ord($bytes[7]) !== 3) {
            throw new RuntimeException('The imagery archive is not a PMTiles v3 file.');
        }

        $e7 = static function (int $offset) use ($bytes): float {
            /** @var array{1: int} $value */
            $value = unpack('l', substr($bytes, $offset, 4));

            return $value[1] / 10_000_000;
        };

        return [
            'tile_type' => ord($bytes[99]),
            'min_zoom' => ord($bytes[100]),
            'max_zoom' => ord($bytes[101]),
            'west' => $e7(102),
            'south' => $e7(106),
            'east' => $e7(110),
            'north' => $e7(114),
        ];
    }
}
