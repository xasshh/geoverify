<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enumerate;

use App\Domain\Enumerate\Actions\AssembleEnumerateReport;
use App\Domain\Enumerate\Actions\EnumerateContext;
use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Verification\Exports\LoopbackPrint;
use App\Domain\Verification\Exports\PdfRenderer;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Business Verification Report, printed on demand like the certificate.
 *
 * Not stored: an interim report printed on day 12 and a final one printed
 * after day 30 are different documents, and a file on disk would go on
 * asserting whichever was printed last. Every download is an event.
 */
final class ReportController
{
    public function download(Request $request, string $reference, EnumerateContext $context, PdfRenderer $renderer, AssembleEnumerateReport $assemble): StreamedResponse
    {
        $account = EnumerateController::account($request);

        $found = EnumerateRequest::query()
            ->where('reference', $reference)
            ->where('wallet_id', $context->wallet($request, $account)->id)
            ->first() ?? throw new NotFoundHttpException;

        if (! $renderer->available()) {
            abort(503, 'No headless browser is installed on this server, so a report cannot be printed.');
        }

        // Minted before the browser fetches the page, so the page it prints
        // carries the token the QR check will answer to.
        $assemble->token($found);

        $url = URL::temporarySignedRoute('enumerate.report.render', now()->addMinutes(2), ['reference' => $found->reference], absolute: false);
        $file = tempnam(sys_get_temp_dir(), 'enumerate-report-').'.pdf';

        $renderer->render(app(LoopbackPrint::class)->url($request, $url), $file);

        VerificationEvent::recordForBuyer($found, 'enumerate.report_downloaded', $account, [
            'final' => $found->status->finished(),
        ]);

        return response()->streamDownload(
            function () use ($file): void {
                try {
                    echo (string) file_get_contents($file);
                } finally {
                    @unlink($file);
                }
            },
            'enumerate-'.strtolower($found->reference).'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * The report as HTML, for the browser that prints it. Signed and short
     * lived because that browser has no session, and refused off the loopback
     * interface so a leaked signature is useless anywhere else.
     */
    public function render(Request $request, string $reference, AssembleEnumerateReport $assemble): ViewContract
    {
        if (! $request->hasValidSignature(absolute: false)) {
            abort(403, 'That link has expired.');
        }

        if (! in_array($request->ip(), ['127.0.0.1', '::1', null], true)) {
            abort(403, 'The report renders only on the host that asked for it.');
        }

        $found = EnumerateRequest::query()->where('reference', $reference)->firstOrFail();

        return view('exports.enumerate-report', ['report' => $assemble($found)]);
    }
}
