<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Party\Actions\ActingParty;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Verification\Enums\OrderStatus;
use App\Domain\Verification\Exports\AssembleCertificate;
use App\Domain\Verification\Exports\LoopbackPrint;
use App\Domain\Verification\Exports\PdfRenderer;
use App\Domain\Verification\Models\VerificationEvent;
use App\Domain\Verification\Models\VerificationOrder;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The certificate a business paid for, printed on demand.
 *
 * Same shape as the evidence pack: a headless browser fetches this application
 * over the loopback interface using a short lived signed URL, so the document
 * inherits the stylesheet and the self hosted fonts rather than growing a second
 * typographic pipeline.
 *
 * Generated rather than stored. A certificate held on disk is a certificate that
 * keeps asserting a finding after the business has been re-verified, closed or
 * had the result overturned, and the one thing this document must not do is
 * outlive its own truth quietly.
 */
final class CertificateController extends Controller
{
    public function __construct(private readonly ActingParty $acting) {}

    public function download(
        Request $request,
        VerificationOrder $order,
        PdfRenderer $renderer,
    ): StreamedResponse {
        $this->authoriseParty($request, $order);

        if ($order->status !== OrderStatus::Completed) {
            abort(404, 'This verification has not been completed yet.');
        }

        if (! $renderer->available()) {
            abort(503, 'No headless browser is installed on this server, so a certificate cannot be printed.');
        }

        // Signed relative to the path rather than the host, because the browser
        // is sent to the loopback address and an absolute signature would cover
        // a hostname that is deliberately not the one being fetched.
        $url = URL::temporarySignedRoute(
            'portal.certificate.render',
            now()->addMinutes(2),
            ['order' => $order->id],
            absolute: false,
        );

        $file = tempnam(sys_get_temp_dir(), 'geoverify-certificate-').'.pdf';

        $renderer->render(app(LoopbackPrint::class)->url($request, $url), $file);

        VerificationEvent::record($order, 'certificate.downloaded', null, [
            'reference' => $order->reference,
        ], VerificationEvent::ACTOR_PARTY);

        return response()->streamDownload(
            function () use ($file): void {
                try {
                    echo (string) file_get_contents($file);
                } finally {
                    @unlink($file);
                }
            },
            'geoverify-'.strtolower($order->reference).'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * The certificate as HTML, for the browser that prints it.
     *
     * Signed rather than authenticated because the printing browser has no
     * session, and refused off the loopback interface so a signature that leaked
     * is still useless to anybody outside this host.
     */
    public function render(
        Request $request,
        VerificationOrder $order,
        AssembleCertificate $assemble,
    ): ViewContract {
        if (! $request->hasValidSignature(absolute: false)) {
            abort(403, 'That link has expired.');
        }

        if (! in_array($request->ip(), ['127.0.0.1', '::1', null], true)) {
            abort(403, 'The certificate renders only on the host that asked for it.');
        }

        return view('exports.certificate', ['certificate' => $assemble($order)]);
    }

    /**
     * A party may print a certificate for a business it actually manages.
     *
     * Resolved through ActingParty like every other portal screen, because one
     * account can act for several businesses and the answer to "which party is
     * this" belongs in one place rather than in each controller that asks.
     *
     * Refused as 404 rather than 403. A stranger probing order ids should not
     * learn which of them exist.
     */
    private function authoriseParty(Request $request, VerificationOrder $order): void
    {
        $account = $request->user('portal');

        if (! $account instanceof PortalAccount) {
            abort(404);
        }

        $membership = $this->acting->forRequest($request, $account);

        if (! $membership instanceof PartyUser) {
            abort(404);
        }

        $controls = PartyBusiness::query()
            ->where('enterprise_id', $order->enterprise_id)
            ->where('party_id', $membership->party_id)
            ->where('status', PartyBusiness::STATUS_ACTIVE)
            ->exists();

        if (! $controls) {
            abort(404);
        }
    }
}
