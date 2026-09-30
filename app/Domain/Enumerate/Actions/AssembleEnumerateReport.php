<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Actions;

use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Media\Models\Media;
use App\Domain\Verification\Exports\QrCode;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The Business Verification Report, to board 33.
 *
 * Built on PresentEnumerateRequest::page and nothing else, so the printed
 * report can never say what the requester's page withheld: the same registry
 * facts, the same exterior photographs (embedded, because the printing
 * browser has no session to fetch a link with), the same daily log. What it
 * adds is the score, whether it is interim or final, and the QR code.
 *
 * The QR code carries the request's report token, minted here on the first
 * print: forty random characters, checked at verify/{token} by anybody the
 * requester hands the report to.
 */
final class AssembleEnumerateReport
{
    public function __construct(
        private readonly PresentEnumerateRequest $present,
        private readonly ScoreEnumerateRequest $score,
        private readonly QrCode $qr,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(EnumerateRequest $request): array
    {
        $token = $this->token($request);
        $page = $this->present->page($request->loadMissing('requester'));
        $result = ($this->score)($request);
        $checks = $request->latestChecks();

        /** @var array<string, mixed>|null $location */
        $location = $page['location'];

        if (is_array($location) && is_array($location['photos'] ?? null)) {
            $location['photos'] = array_map(fn (array $p): array => $p + ['data' => $this->embed((int) $p['id'])], $location['photos']);
        }

        $url = route('verify.show', ['token' => $token]);

        return [
            'final' => $request->status->finished(),
            'issuedAt' => now(config('app.timezone')),
            'request' => $page,
            'tin' => $checks['tin']?->facts['tin'] ?? null,
            'location' => $location,
            'score' => $result['score'],
            'finding' => $result['finding'],
            'parts' => $result['parts'],
            'verifyUrl' => $url,
            'qr' => $this->qr->svg($url, 120),
        ];
    }

    /** The token the QR code carries, minted once and kept. */
    public function token(EnumerateRequest $request): string
    {
        if ($request->report_token !== null) {
            return $request->report_token;
        }

        $request->forceFill(['report_token' => Str::lower(Str::random(40))])->save();

        return (string) $request->report_token;
    }

    /** A photograph as a data URI, for a page printed without a session. */
    private function embed(int $mediaId): ?string
    {
        $media = Media::query()->find($mediaId);

        if ($media === null || ! Storage::disk($media->disk)->exists($media->disk_path)) {
            return null;
        }

        $mime = Storage::disk($media->disk)->mimeType($media->disk_path) ?: 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode((string) Storage::disk($media->disk)->get($media->disk_path));
    }
}
