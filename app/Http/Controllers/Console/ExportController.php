<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domain\Coverage\Models\CoverageArea;
use App\Domain\Coverage\Models\GridCell;
use App\Domain\Registry\Models\Structure;
use App\Domain\Verification\Actions\RecordExport;
use App\Domain\Verification\Exports\EnterpriseCsv;
use App\Domain\Verification\Exports\EvidencePack;
use App\Domain\Verification\Exports\ExportScope;
use App\Domain\Verification\Exports\PdfRenderer;
use App\Domain\Verification\Exports\StructureGeoJson;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What leaves this system, and the record that it left.
 */
final class ExportController
{
    public function index(Request $request, PdfRenderer $renderer): Response
    {
        Gate::authorize('export', Structure::class);

        $area = $this->area($request);

        return Inertia::render('console/Exports', [
            'areas' => CoverageArea::query()->orderBy('name')->get(['id', 'name'])
                ->map(static fn (CoverageArea $a): array => ['id' => $a->id, 'name' => $a->name])
                ->all(),
            'area' => $area === null ? null : [
                'id' => $area->id,
                'name' => $area->name,
                'client' => $area->client_name,
                'contractRef' => $area->contract_ref,
            ],
            'counts' => $area === null ? null : $this->counts($area),
            'cells' => $area === null ? [] : $this->cellsWithWork($area),
            'recent' => $area === null ? [] : $this->recent($area),
            // Said on the screen rather than discovered at the moment somebody
            // needs a pack for a client.
            'packAvailable' => $renderer->available(),
        ]);
    }

    public function structures(Request $request, StructureGeoJson $geojson, RecordExport $record): StreamedResponse
    {
        Gate::authorize('export', Structure::class);

        $scope = $this->scope($request);

        return $record(
            $scope,
            $this->actor($request),
            'geojson',
            $scope->filename('geojson'),
            'application/geo+json',
            $geojson->stream($scope),
        );
    }

    public function enterprises(Request $request, EnterpriseCsv $csv, RecordExport $record): StreamedResponse
    {
        Gate::authorize('export', Structure::class);

        $scope = $this->scope($request);

        return $record(
            $scope,
            $this->actor($request),
            'csv',
            $scope->filename('csv'),
            'text/csv; charset=utf-8',
            $csv->stream($scope),
        );
    }

    /**
     * The evidence pack, printed by a headless browser.
     *
     * The browser fetches the document from this application over the loopback
     * interface using a signed, short lived URL. That is deliberate: rendering
     * the same HTML and CSS the console already serves means the pack inherits
     * the self hosted fonts and their subsets rather than growing a second
     * typographic pipeline that can drift from the first.
     */
    public function pack(
        Request $request,
        GridCell $cell,
        PdfRenderer $renderer,
        RecordExport $record,
    ): StreamedResponse {
        Gate::authorize('export', Structure::class);

        $area = CoverageArea::query()->findOrFail($cell->coverage_area_id);
        $scope = new ExportScope($area, $cell, $request->boolean('all'));

        if (! $renderer->available()) {
            abort(503, 'No headless browser is installed on this server, so a pack cannot be printed.');
        }

        // Signed relative to the path, not the host. The browser is sent to
        // the loopback address rather than to APP_URL, and an absolute
        // signature would cover a hostname that is deliberately not the one
        // being fetched.
        $url = URL::temporarySignedRoute(
            'console.exports.pack.render',
            now()->addMinutes(2),
            [
                'cell' => $cell->id,
                'all' => $request->boolean('all') ? 1 : 0,
                'by' => $this->actor($request)->id,
            ],
            absolute: false,
        );

        $file = tempnam(sys_get_temp_dir(), 'geoverify-pack-').'.pdf';

        $renderer->render($this->loopback($request, $url), $file);

        return $record(
            $scope,
            $this->actor($request),
            'pdf',
            $scope->filename('pdf'),
            'application/pdf',
            $this->readAndDelete($file),
        );
    }

    /**
     * The pack as HTML, for the browser that prints it and for a preview.
     *
     * Signed rather than authenticated, because the browser doing the printing
     * has no session. It is additionally refused off the loopback interface, so
     * a signature that leaked is still useless to anyone outside this host.
     */
    public function packHtml(Request $request, GridCell $cell, EvidencePack $pack): ViewContract
    {
        if (! $request->hasValidSignature(absolute: false)) {
            abort(403, 'That link has expired.');
        }

        if (! in_array($request->ip(), ['127.0.0.1', '::1', null], true)) {
            abort(403, 'The pack renders only on the host that asked for it.');
        }

        $by = User::query()->findOrFail($request->integer('by'));

        return view('exports.pack', [
            'pack' => $pack($cell, $by, $request->boolean('all')),
        ]);
    }

    /**
     * The rendered file, yielded once and then removed.
     *
     * A pack is printed to a temporary file because that is the only thing the
     * browser's print-to-pdf will write to. It does not outlive the download.
     *
     * @return \Generator<int, string>
     */
    private function readAndDelete(string $file): \Generator
    {
        try {
            yield (string) file_get_contents($file);
        } finally {
            @unlink($file);
        }
    }

    /**
     * The URL the local browser should fetch.
     *
     * Not APP_URL. That is what the outside world calls this application, and it
     * is not necessarily a name this host can resolve or a port it answers on: a
     * pack that only prints when DNS agrees with itself is a pack that fails in
     * production. The loopback address with the port this very request arrived
     * on is always somewhere the application is listening.
     */
    private function loopback(Request $request, string $url): string
    {
        $parts = parse_url($url);
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        $base = config('services.chromium.base_url');

        if (is_string($base) && $base !== '') {
            return rtrim($base, '/').$path;
        }

        return sprintf('%s://127.0.0.1:%d%s', $request->getScheme(), $request->getPort(), $path);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function area(Request $request): ?CoverageArea
    {
        $id = $request->integer('area') ?: null;

        return $id === null
            ? CoverageArea::query()->orderBy('name')->first()
            : CoverageArea::query()->find($id);
    }

    /**
     * The requested scope, with the cell checked against the mandate.
     *
     * A grid cell id that belongs to another mandate would otherwise widen an
     * export past what the caller asked for, which is the kind of hole that only
     * shows up once a client receives somebody else's register.
     */
    private function scope(Request $request): ExportScope
    {
        $validated = $request->validate([
            'area' => ['required', 'integer', 'exists:coverage_areas,id'],
            'cell' => ['nullable', 'integer', 'exists:grid_cells,id'],
            'all' => ['nullable', 'boolean'],
        ]);

        $area = CoverageArea::query()->findOrFail($validated['area']);

        $cell = isset($validated['cell'])
            ? GridCell::query()
                ->where('coverage_area_id', $area->id)
                ->findOrFail($validated['cell'])
            : null;

        return new ExportScope($area, $cell, (bool) ($validated['all'] ?? false));
    }

    /**
     * @return array<string, int>
     */
    private function counts(CoverageArea $area): array
    {
        $row = DB::selectOne(<<<'SQL'
            select
                count(*) filter (where structures.status = ?) as accepted,
                count(*) as captured,
                (
                    select count(*) from enterprises
                     join structures s on s.id = enterprises.structure_id
                    where s.coverage_area_id = ? and s.status = ?
                ) as accepted_enterprises,
                (
                    select count(*) from enterprises
                     join structures s on s.id = enterprises.structure_id
                    where s.coverage_area_id = ?
                ) as enterprises
            from structures
            where structures.coverage_area_id = ?
        SQL, [
            Structure::STATUS_ACCEPTED,
            $area->id, Structure::STATUS_ACCEPTED,
            $area->id,
            $area->id,
        ]);

        return [
            'accepted' => (int) ($row->accepted ?? 0),
            'captured' => (int) ($row->captured ?? 0),
            'acceptedEnterprises' => (int) ($row->accepted_enterprises ?? 0),
            'enterprises' => (int) ($row->enterprises ?? 0),
        ];
    }

    /**
     * Cells that have something in them, so the picker never offers empty ground.
     *
     * @return list<array<string, mixed>>
     */
    private function cellsWithWork(CoverageArea $area): array
    {
        $rows = DB::select(<<<'SQL'
            select cells.id, to_hex(cells.h3_index) as h3,
                   cells.structures_captured, cells.structures_accepted
              from grid_cells cells
             where cells.coverage_area_id = ? and cells.structures_captured > 0
             order by cells.structures_accepted desc, cells.structures_captured desc
             limit 200
        SQL, [$area->id]);

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'h3' => (string) $row->h3,
            'captured' => (int) $row->structures_captured,
            'accepted' => (int) $row->structures_accepted,
        ], $rows);
    }

    /**
     * What has already left, most recent first.
     *
     * On the same screen as the buttons on purpose: a supervisor about to send a
     * register to a client should be able to see that somebody sent one an hour
     * ago without going looking for the log.
     *
     * @return list<array<string, mixed>>
     */
    private function recent(CoverageArea $area): array
    {
        return VerificationEvent::query()
            ->where('subject_type', $area->getMorphClass())
            ->where('subject_id', $area->id)
            ->where('event', 'export.taken')
            ->orderByDesc('id')
            ->limit(12)
            ->get()
            ->map(static fn (VerificationEvent $event): array => [
                'id' => $event->id,
                'takenAt' => $event->occurred_at->toIso8601String(),
                'by' => $event->actor_label,
                'format' => $event->evidence['format'] ?? null,
                'rows' => $event->evidence['rows'] ?? null,
                'bytes' => $event->evidence['bytes'] ?? null,
                'sha256' => $event->evidence['sha256'] ?? null,
                'h3' => $event->evidence['h3'] ?? null,
                'acceptedOnly' => $event->evidence['accepted_only'] ?? null,
            ])
            ->all();
    }
}
