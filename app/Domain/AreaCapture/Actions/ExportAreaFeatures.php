<?php

declare(strict_types=1);

namespace App\Domain\AreaCapture\Actions;

use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\ClientUser;
use App\Domain\Campaign\Models\FeatureClassVersion;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

/**
 * A campaign's mapped land as a file, with the dictionary that explains it.
 *
 * Every format leaves as one zip holding the data, `data-dictionary.csv`
 * (built from the class versions, so every column a client sees is defined
 * somewhere they can read) and a README saying what was asked for, when, and
 * where the shapes came from. GeoJSON and CSV are written here; GeoPackage,
 * Shapefile and KML are converted from GeoJSON lines by ogr2ogr, the same tool
 * that reads imports in.
 *
 * Live features only, at their current revision: a withdrawn feature is on
 * record, not on the map. Nothing about who captured a feature leaves: the
 * client bought the land, not the officers.
 *
 * Every export writes a verification event carrying the filters, the row count
 * and the SHA-256 of the zip, so a copy produced months later can be checked
 * against what was handed over.
 */
final class ExportAreaFeatures
{
    public const FORMATS = ['geojson', 'gpkg', 'shp', 'kml', 'csv'];

    /** @var array<string, array<string, string>> What each class's answer columns became in its Shapefile. */
    private array $shapefileNames = [];

    public const METHOD_GROUPS = [
        'field' => ['field_walked', 'field_drawn', 'field_verified'],
        'desk' => ['desk_digitised', 'imported'],
    ];

    /**
     * The columns every row carries, kept to ten characters so a Shapefile
     * holds them unchanged.
     */
    private const FIXED = [
        'id' => ['Feature id', 'text', 'Stable across revisions. The same feature keeps it when corrected.'],
        'class' => ['Class key', 'text', 'Which feature class this is.'],
        'class_name' => ['Class', 'text', 'The class as it reads on screen.'],
        'mandate' => ['Mandate', 'text', 'The coverage area the feature lies in.'],
        'method' => ['Capture method', 'text', 'field_walked, field_drawn, field_verified, desk_digitised or imported.'],
        'verified' => ['Verification', 'text', 'unverified, verified (checked on the ground), rejected or needs_revisit.'],
        'area_ha' => ['Area', 'decimal', 'Hectares, computed by PostGIS on the ellipsoid. Empty for points and lines.'],
        'length_m' => ['Length', 'decimal', 'Metres, computed by PostGIS on the ellipsoid. Perimeter for areas.'],
        'captured' => ['Captured on', 'date', 'When this revision was drawn or recorded.'],
        'imagery' => ['Imagery date', 'date', 'The date of the satellite image it was drawn over, when drawn at the desk.'],
        'source' => ['Source', 'text', 'The file or dataset a desk or imported feature came from.'],
        'schema_ver' => ['Schema version', 'integer', 'The version of the class questions the answers follow.'],
        'confidence' => ['Confidence', 'integer', '0 to 100, from the capture signals. Empty until scored.'],
    ];

    /**
     * @param  array{classes?: list<int>, method?: string|null, verification?: list<string>, area?: int|null}  $filters
     * @return array{path: string, filename: string, rows: int, sha256: string, bytes: int}
     */
    public function __invoke(Campaign $campaign, string $format, array $filters, User|ClientUser $actor): array
    {
        if (! in_array($format, self::FORMATS, true)) {
            throw ValidationException::withMessages(['format' => 'Choose GeoJSON, GeoPackage, Shapefile, KML or CSV.']);
        }

        $this->shapefileNames = [];
        $dir = storage_path('app/private/area-exports/'.Str::lower((string) Str::ulid()));
        File::ensureDirectoryExists($dir.'/data');

        try {
            $dictionary = $this->dictionary($campaign, $filters['classes'] ?? []);
            [$rows, $perClass] = $this->writeLines($campaign, $filters, $dictionary, $dir);

            $files = match ($format) {
                'geojson' => [$this->geojson($dir)],
                'csv' => [$this->csv($dir, $dictionary)],
                'gpkg' => $rows === 0 ? [] : [$this->gpkg($dir, $perClass)],
                'shp' => $rows === 0 ? [] : $this->shapefiles($dir, $perClass, $dictionary),
                'kml' => $rows === 0 ? [] : [$this->kml($dir)],
            };

            $this->writeDictionary($dir, $dictionary, $format === 'shp');
            $this->writeReadme($dir, $campaign, $format, $filters, $rows, $perClass);

            $filename = implode('_', [
                'geoverify',
                str($campaign->code)->lower()->slug()->value(),
                'land',
                $format,
                now()->format('Ymd-His'),
            ]).'.zip';

            $zipPath = $dir.'/'.$filename;
            $zip = new ZipArchive;

            if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
                throw new RuntimeException('The export could not be packed.');
            }

            foreach ($files as $file) {
                $zip->addFile($file, Str::after($file, $dir.'/data/'));
            }

            $zip->addFile($dir.'/data-dictionary.csv', 'data-dictionary.csv');
            $zip->addFile($dir.'/README.txt', 'README.txt');
            $zip->close();

            $sha256 = (string) hash_file('sha256', $zipPath);
            $bytes = (int) filesize($zipPath);

            $evidence = [
                'format' => $format,
                'filters' => $filters,
                'rows' => $rows,
                'sha256' => $sha256,
                'bytes' => $bytes,
                'filename' => $filename,
            ];

            $actor instanceof ClientUser
                ? VerificationEvent::recordForClient($campaign, 'area_features.exported', $actor, $evidence)
                : VerificationEvent::record($campaign, 'area_features.exported', $actor, $evidence);

            return ['path' => $zipPath, 'filename' => $filename, 'rows' => $rows, 'sha256' => $sha256, 'bytes' => $bytes];
        } catch (\Throwable $e) {
            File::deleteDirectory($dir);

            throw $e;
        } finally {
            // The zip is kept for the response to stream; everything else goes.
            File::deleteDirectory($dir.'/data');
        }
    }

    /** Remove an export once it has been handed over. */
    public static function discard(string $zipPath): void
    {
        File::deleteDirectory(dirname($zipPath));
    }

    /**
     * Every answer column each class can carry, across all its versions, the
     * latest wording winning.
     *
     * @param  list<int>  $classIds
     * @return array<string, array{label: string, geometry: string, attributes: array<string, array<string, mixed>>}>
     */
    private function dictionary(Campaign $campaign, array $classIds): array
    {
        $versions = FeatureClassVersion::query()
            ->join('feature_classes', 'feature_classes.id', '=', 'feature_class_versions.feature_class_id')
            ->where('feature_classes.campaign_id', $campaign->id)
            ->when($classIds !== [], fn ($q) => $q->whereIn('feature_classes.id', $classIds))
            ->orderBy('feature_classes.sort_order')
            ->orderBy('feature_classes.id')
            ->orderBy('feature_class_versions.version')
            ->get(['feature_class_versions.*', 'feature_classes.key as class_key', 'feature_classes.label as class_label', 'feature_classes.geometry_type as class_geometry']);

        $classes = [];

        foreach ($versions as $version) {
            $key = (string) $version->getAttribute('class_key');
            $classes[$key] ??= [
                'label' => (string) $version->getAttribute('class_label'),
                'geometry' => (string) $version->getAttribute('class_geometry'),
                'attributes' => [],
            ];

            foreach ((array) $version->attribute_schema as $attribute) {
                $classes[$key]['attributes'][(string) $attribute['key']] = (array) $attribute;
            }
        }

        return $classes;
    }

    /**
     * One GeoJSON line per feature, into one combined file and one file per
     * class, streamed from a cursor so a mandate costs a row of memory.
     *
     * @param  array{classes?: list<int>, method?: string|null, verification?: list<string>, area?: int|null}  $filters
     * @param  array<string, array{label: string, geometry: string, attributes: array<string, array<string, mixed>>}>  $dictionary
     * @return array{0: int, 1: array<string, int>}
     */
    private function writeLines(Campaign $campaign, array $filters, array $dictionary, string $dir): array
    {
        $where = ['f.campaign_id = ?', "f.status = 'live'"];
        $bindings = [$campaign->id];

        if (($filters['classes'] ?? []) !== []) {
            $where[] = 'f.feature_class_id IN ('.implode(',', array_fill(0, count($filters['classes']), '?')).')';
            array_push($bindings, ...$filters['classes']);
        }

        if (($filters['method'] ?? null) !== null) {
            $methods = self::METHOD_GROUPS[$filters['method']] ?? [$filters['method']];
            $where[] = 'r.capture_method IN ('.implode(',', array_fill(0, count($methods), '?')).')';
            array_push($bindings, ...$methods);
        }

        if (($filters['verification'] ?? []) !== []) {
            $where[] = 'f.verification_status IN ('.implode(',', array_fill(0, count($filters['verification']), '?')).')';
            array_push($bindings, ...$filters['verification']);
        }

        if (($filters['area'] ?? null) !== null) {
            $where[] = 'f.coverage_area_id = ?';
            $bindings[] = $filters['area'];
        }

        $sql = 'SELECT f.client_uuid, fc.key, fc.label, ca.name AS mandate, r.capture_method, f.verification_status,
                       r.area_ha, r.length_m, r.captured_at, r.imagery_date, b.source_name, v.version,
                       f.confidence_score, r.answers, ST_AsGeoJSON(r.geom, 7) AS geometry, ST_AsText(r.geom) AS wkt
                  FROM area_features f
                  JOIN area_feature_revisions r ON r.id = f.current_revision_id
                  JOIN feature_classes fc ON fc.id = f.feature_class_id
                  JOIN feature_class_versions v ON v.id = r.feature_class_version_id
                  JOIN coverage_areas ca ON ca.id = f.coverage_area_id
                  LEFT JOIN area_feature_batches b ON b.id = r.area_feature_batch_id
                 WHERE '.implode(' AND ', $where).'
                 ORDER BY fc.sort_order, fc.id, f.id';

        $all = fopen($dir.'/data/all.geojsonl', 'w');
        $wkt = fopen($dir.'/data/all.wkt.jsonl', 'w');
        $handles = [];
        $perClass = [];
        $rows = 0;

        if ($all === false || $wkt === false) {
            throw new RuntimeException('The export could not be written.');
        }

        foreach (DB::connection()->cursor($sql, $bindings) as $row) {
            $key = (string) $row->key;
            $answers = json_decode((string) $row->answers, true) ?: [];
            $properties = [
                'id' => $row->client_uuid,
                'class' => $key,
                'class_name' => $row->label,
                'mandate' => $row->mandate,
                'method' => $row->capture_method,
                'verified' => $row->verification_status,
                'area_ha' => $row->area_ha === null ? null : (float) $row->area_ha,
                'length_m' => $row->length_m === null ? null : (float) $row->length_m,
                'captured' => substr((string) $row->captured_at, 0, 10),
                'imagery' => $row->imagery_date,
                'source' => $row->source_name,
                'schema_ver' => (int) $row->version,
                'confidence' => $row->confidence_score === null ? null : (int) $row->confidence_score,
            ];

            // Answers by the class's own keys, in dictionary order, so every
            // row of a class has the same columns.
            foreach (array_keys($dictionary[$key]['attributes'] ?? []) as $attribute) {
                $value = $answers[$attribute] ?? null;
                $properties[$attribute] = is_array($value) ? implode('; ', array_map('strval', $value)) : $value;
            }

            $line = json_encode(['type' => 'Feature', 'geometry' => json_decode((string) $row->geometry, true), 'properties' => $properties], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
            fwrite($all, $line);
            fwrite($wkt, json_encode(['wkt' => $row->wkt, 'properties' => $properties], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");

            if (! isset($handles[$key])) {
                $handle = fopen($dir.'/data/class-'.$key.'.geojsonl', 'w');

                if ($handle === false) {
                    throw new RuntimeException('The export could not be written.');
                }

                $handles[$key] = $handle;
            }

            fwrite($handles[$key], $line);
            $perClass[$key] = ($perClass[$key] ?? 0) + 1;
            $rows++;
        }

        fclose($all);
        fclose($wkt);

        foreach ($handles as $handle) {
            fclose($handle);
        }

        return [$rows, $perClass];
    }

    private function geojson(string $dir): string
    {
        $out = $dir.'/data/features.geojson';
        $target = fopen($out, 'w');
        $source = fopen($dir.'/data/all.geojsonl', 'r');

        if ($target === false || $source === false) {
            throw new RuntimeException('The export could not be written.');
        }

        fwrite($target, '{"type":"FeatureCollection","features":[');
        $first = true;

        while (($line = fgets($source)) !== false) {
            fwrite($target, ($first ? "\n" : ",\n").rtrim($line, "\n"));
            $first = false;
        }

        fwrite($target, "\n]}\n");
        fclose($source);
        fclose($target);

        return $out;
    }

    /**
     * @param  array<string, array{label: string, geometry: string, attributes: array<string, array<string, mixed>>}>  $dictionary
     */
    private function csv(string $dir, array $dictionary): string
    {
        $answerColumns = [];

        foreach ($dictionary as $class) {
            foreach (array_keys($class['attributes']) as $key) {
                $answerColumns[$key] = true;
            }
        }

        $columns = [...array_keys(self::FIXED), ...array_keys($answerColumns), 'wkt'];
        $out = $dir.'/data/features.csv';
        $target = fopen($out, 'w');
        $source = fopen($dir.'/data/all.wkt.jsonl', 'r');

        if ($target === false || $source === false) {
            throw new RuntimeException('The export could not be written.');
        }

        fputcsv($target, $columns, escape: '');

        while (($line = fgets($source)) !== false) {
            /** @var array{wkt: string, properties: array<string, mixed>} $row */
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $values = [];

            foreach ($columns as $column) {
                $value = $column === 'wkt' ? $row['wkt'] : ($row['properties'][$column] ?? null);
                $values[] = is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
            }

            fputcsv($target, $values, escape: '');
        }

        fclose($source);
        fclose($target);

        return $out;
    }

    /**
     * One layer per class, named by its key.
     *
     * @param  array<string, int>  $perClass
     */
    private function gpkg(string $dir, array $perClass): string
    {
        $out = $dir.'/data/features.gpkg';
        $first = true;

        foreach (array_keys($perClass) as $key) {
            $this->ogr([
                '-f', 'GPKG', $out, $dir.'/data/class-'.$key.'.geojsonl',
                '-nln', $key, '-a_srs', 'EPSG:4326',
                ...($first ? [] : ['-update', '-append']),
            ]);
            $first = false;
        }

        return $out;
    }

    /**
     * One Shapefile per class. Answer columns are renamed to ten characters
     * here, deliberately, and the dictionary says what each became, rather
     * than leaving GDAL to truncate them in a way nobody wrote down.
     *
     * @param  array<string, int>  $perClass
     * @param  array<string, array{label: string, geometry: string, attributes: array<string, array<string, mixed>>}>  $dictionary
     * @return list<string>
     */
    private function shapefiles(string $dir, array $perClass, array $dictionary): array
    {
        $files = [];
        File::ensureDirectoryExists($dir.'/data/shapefile');

        foreach (array_keys($perClass) as $key) {
            $names = $this->shortNames($dictionary[$key]['attributes'] ?? []);

            $this->shapefileNames[$key] = $names;

            $renamed = $dir.'/data/shp-'.$key.'.geojsonl';
            $source = fopen($dir.'/data/class-'.$key.'.geojsonl', 'r');
            $target = fopen($renamed, 'w');

            if ($source === false || $target === false) {
                throw new RuntimeException('The export could not be written.');
            }

            while (($line = fgets($source)) !== false) {
                $feature = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $properties = [];

                foreach ($feature['properties'] as $column => $value) {
                    $properties[$names[$column] ?? $column] = $value;
                }

                $feature['properties'] = $properties;
                fwrite($target, json_encode($feature, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
            }

            fclose($source);
            fclose($target);

            $polygon = ($dictionary[$key]['geometry'] ?? '') === 'polygon';
            $this->ogr([
                '-f', 'ESRI Shapefile', $dir.'/data/shapefile/'.$key.'.shp', $renamed,
                '-a_srs', 'EPSG:4326', '-lco', 'ENCODING=UTF-8',
                ...($polygon ? ['-nlt', 'PROMOTE_TO_MULTI'] : []),
            ]);

            foreach (['shp', 'shx', 'dbf', 'prj', 'cpg'] as $extension) {
                $path = $dir.'/data/shapefile/'.$key.'.'.$extension;

                if (is_file($path)) {
                    $files[] = $path;
                }
            }
        }

        return $files;
    }

    private function kml(string $dir): string
    {
        $out = $dir.'/data/features.kml';
        $this->ogr(['-f', 'KML', $out, $dir.'/data/all.geojsonl', '-nln', 'features', '-dsco', 'NameField=class_name']);

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $attributes
     * @return array<string, string>
     */
    private function shortNames(array $attributes): array
    {
        $used = array_fill_keys(array_keys(self::FIXED), true);
        $names = [];

        foreach (array_keys($attributes) as $key) {
            $short = substr($key, 0, 10);
            $n = 1;

            while (isset($used[$short])) {
                $short = substr($key, 0, 8).str_pad((string) $n++, 2, '0', STR_PAD_LEFT);
            }

            $used[$short] = true;
            $names[$key] = $short;
        }

        return $names;
    }

    /**
     * @param  array<string, array{label: string, geometry: string, attributes: array<string, array<string, mixed>>}>  $dictionary
     */
    private function writeDictionary(string $dir, array $dictionary, bool $shapefile): void
    {
        $handle = fopen($dir.'/data-dictionary.csv', 'w');

        if ($handle === false) {
            throw new RuntimeException('The export could not be written.');
        }

        fputcsv($handle, ['layer', 'column', ...($shapefile ? ['shapefile_column'] : []), 'label', 'type', 'unit', 'options', 'required', 'where_answered', 'notes'], escape: '');

        foreach (self::FIXED as $column => [$label, $type, $notes]) {
            fputcsv($handle, ['(every layer)', $column, ...($shapefile ? [$column] : []), $label, $type, '', '', 'yes', '', $notes], escape: '');
        }

        foreach ($dictionary as $key => $class) {
            foreach ($class['attributes'] as $column => $attribute) {
                fputcsv($handle, [
                    $key.' ('.$class['label'].', '.$class['geometry'].')',
                    $column,
                    ...($shapefile ? [$this->shapefileNames[$key][$column] ?? $column] : []),
                    (string) ($attribute['label'] ?? $column),
                    (string) ($attribute['type'] ?? ''),
                    (string) ($attribute['unit'] ?? ''),
                    implode(' | ', array_map('strval', (array) ($attribute['options'] ?? []))),
                    ($attribute['required'] ?? false) ? 'yes' : 'no',
                    ($attribute['field_only'] ?? false) ? 'on the ground only' : 'desk or ground',
                    ($attribute['field_only'] ?? false) ? 'Empty until an officer has visited.' : '',
                ], escape: '');
            }
        }

        fclose($handle);
    }

    /**
     * @param  array{classes?: list<int>, method?: string|null, verification?: list<string>, area?: int|null}  $filters
     * @param  array<string, int>  $perClass
     */
    private function writeReadme(string $dir, Campaign $campaign, string $format, array $filters, int $rows, array $perClass): void
    {
        $sources = DB::table('area_feature_batches as b')
            ->join('coverage_areas as ca', 'ca.id', '=', 'b.coverage_area_id')
            ->where('ca.campaign_id', $campaign->id)
            ->where('b.status', 'done')
            ->whereNotNull('b.source_name')
            ->distinct()
            ->pluck('b.source_name')
            ->all();

        $lines = [
            'GeoVerify land and natural features',
            '',
            'Campaign:    '.$campaign->code.', '.$campaign->name,
            'Format:      '.$format,
            'Generated:   '.now()->toDayDateTimeString().' ('.config('app.timezone').')',
            'Features:    '.number_format($rows),
            'Filters:     '.($this->describe($filters) ?: 'none (every live feature)'),
            '',
            'Per layer:',
            ...array_map(static fn (string $key, int $n): string => '  '.$key.': '.number_format($n), array_keys($perClass), $perClass),
            '',
            'Coordinates are WGS 84 longitude and latitude (EPSG:4326).',
            'Areas and lengths are computed by PostGIS on the ellipsoid.',
            'Columns are explained in data-dictionary.csv.',
            '',
            '"verified" means an officer stood on the ground and confirmed the feature.',
            'Features drawn at the desk or imported are unverified until checked.',
        ];

        if ($sources !== []) {
            $lines[] = '';
            $lines[] = 'Sources of desk and imported features:';

            foreach ($sources as $source) {
                $lines[] = '  '.$source;
            }

            if (collect($sources)->contains(fn (string $s): bool => str_contains($s, 'WorldCover'))) {
                $lines[] = '';
                $lines[] = 'Land cover pre-drawn from ESA WorldCover (c) ESA WorldCover project, contains modified';
                $lines[] = 'Copernicus Sentinel data, licensed CC BY 4.0. Keep this attribution with the data.';
            }
        }

        file_put_contents($dir.'/README.txt', implode("\n", $lines)."\n");
    }

    /**
     * @param  array{classes?: list<int>, method?: string|null, verification?: list<string>, area?: int|null}  $filters
     */
    private function describe(array $filters): string
    {
        $parts = [];

        if (($filters['classes'] ?? []) !== []) {
            $parts[] = 'classes '.implode(', ', DB::table('feature_classes')->whereIn('id', $filters['classes'])->orderBy('id')->pluck('key')->all());
        }

        if (($filters['method'] ?? null) !== null) {
            $parts[] = 'captured '.($filters['method'] === 'field' ? 'in the field' : ($filters['method'] === 'desk' ? 'at the desk or imported' : $filters['method']));
        }

        if (($filters['verification'] ?? []) !== []) {
            $parts[] = 'verification '.implode(', ', $filters['verification']);
        }

        if (($filters['area'] ?? null) !== null) {
            $parts[] = 'mandate '.DB::table('coverage_areas')->where('id', $filters['area'])->value('name');
        }

        return implode('; ', $parts);
    }

    /** @param  list<string>  $arguments */
    private function ogr(array $arguments): void
    {
        $bin = rtrim((string) config('geoverify.imagery.gdal_bin'), '/');
        $result = Process::timeout(600)->run([$bin === '' ? 'ogr2ogr' : "{$bin}/ogr2ogr", ...$arguments]);

        if ($result->failed()) {
            throw new RuntimeException('ogr2ogr failed: '.Str::limit(trim($result->errorOutput()), 400));
        }
    }
}
