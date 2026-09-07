<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Actions\ResolveConsentReceipt;
use App\Domain\Identity\Models\ConsentReceipt;
use App\Domain\Verification\Exports\LoopbackPrint;
use App\Domain\Verification\Exports\PdfRenderer;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A person's copy of what they agreed to.
 *
 * Open, on a token, for the reason the schema gives: the Act grants a right to
 * a copy, and a right that requires an account is a right with a queue in front
 * of it. The party that opted in reaches this from its listing; somebody who
 * was asked in the field and never had an account reaches it from the link they
 * were given.
 *
 * Nothing here writes. Not even a download event: this is an open URL, and an
 * endpoint that appended a row per refresh would let whoever holds the link
 * write into the audit log at will. What matters was already recorded, twice,
 * on the day: the grant, and the withdrawal that supersedes it.
 */
final class ConsentReceiptController extends Controller
{
    /** What the link in a listing, or on a printed slip, opens. */
    public function show(string $token, ResolveConsentReceipt $resolve): Response
    {
        return Inertia::render('public/Receipt', [
            'receipt' => $resolve($token),
            'token' => $token,
        ])->withViewData([
            // One person's document, on a URL nobody else should hold. Indexing
            // it would publish both the agreement and the token that opens it.
            'robots' => 'noindex, nofollow',
        ]);
    }

    /**
     * The same thing as a file, for keeping.
     *
     * Printed through the pipeline the evidence pack and the certificate
     * already use rather than a second one: a Blade view, a signed loopback
     * URL, and the browser on this host.
     */
    public function download(
        Request $request,
        string $token,
        PdfRenderer $renderer,
    ): StreamedResponse {
        $receipt = ConsentReceipt::query()->where('token', $token)->first();

        if (! $receipt instanceof ConsentReceipt) {
            abort(404);
        }

        if (! $renderer->available()) {
            abort(503, 'No headless browser is installed on this server, so a copy cannot be printed.');
        }

        $url = URL::temporarySignedRoute(
            'receipts.render',
            now()->addMinutes(2),
            ['token' => $token],
            absolute: false,
        );

        $file = tempnam(sys_get_temp_dir(), 'geoverify-receipt-').'.pdf';

        $renderer->render(app(LoopbackPrint::class)->url($request, $url), $file);

        return response()->streamDownload(
            function () use ($file): void {
                try {
                    echo (string) file_get_contents($file);
                } finally {
                    @unlink($file);
                }
            },
            'geoverify-consent-'.strtolower(ResolveConsentReceipt::reference($receipt)).'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * The receipt as HTML, for the browser that prints it.
     *
     * Signed and refused off the loopback interface, like the pack and the
     * certificate. The token alone would arguably do here, since it is what
     * guards the page this document copies, but a print route that answered any
     * caller is a print route somebody eventually points at a slow query from
     * the outside.
     */
    public function render(
        Request $request,
        string $token,
        ResolveConsentReceipt $resolve,
    ): ViewContract {
        if (! $request->hasValidSignature(absolute: false)) {
            abort(403, 'That link has expired.');
        }

        if (! in_array($request->ip(), ['127.0.0.1', '::1', null], true)) {
            abort(403, 'The receipt renders only on the host that asked for it.');
        }

        $receipt = $resolve($token);

        if ($receipt['state'] === 'unknown') {
            abort(404);
        }

        return view('exports.consent-receipt', ['receipt' => $receipt]);
    }
}
