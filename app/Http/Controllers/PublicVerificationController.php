<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Verification\Actions\ResolvePublicVerification;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What a QR code resolves to. Open to anybody, by design.
 *
 * The whole value of a printed certificate is that its holder can check it
 * without asking us for an account first, so this route sits outside every
 * guard. What that costs is that the page is a disclosure surface, and the
 * projection is therefore decided in ResolvePublicVerification rather than
 * here: a controller that could widen what it renders is a controller that
 * eventually does.
 *
 * Rate limited because an open endpoint keyed on a token is an oracle if you
 * let somebody ask it a million questions. The tokens are 40 characters of
 * random, so this is depth rather than the actual defence.
 */
final class PublicVerificationController extends Controller
{
    public function __invoke(Request $request, string $token, ResolvePublicVerification $resolve): Response
    {
        $result = $resolve($token);

        return Inertia::render('public/Verify', [
            'result' => $result,
        ])->withViewData([
            // A certificate check is a transient answer about one document, not
            // a directory page. Indexing it would put every token anybody ever
            // scanned into a search engine, which is the opposite of what an
            // opaque token is for.
            'robots' => 'noindex, nofollow',
        ]);
    }
}
