<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Claim\Models\PartyBusiness;
use App\Domain\Media\Actions\PublishStorefrontPhoto;
use App\Domain\Media\Models\Media;
use App\Domain\Party\Actions\ActingParty;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Models\Enterprise;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Photographs a business shows of itself.
 *
 * The only route in the portal that publishes anything to strangers directly.
 * A correction is a proposal a supervisor rules on; publication is a switch on
 * a record we already hold. This puts a new file in front of the public on the
 * party's own authority, so the check that they control the business is the
 * whole of the authorisation and it happens before the file is read.
 */
final class StorefrontPhotoController extends Controller
{
    public function __construct(private readonly ActingParty $acting) {}

    public function store(
        Request $request,
        Enterprise $enterprise,
        PublishStorefrontPhoto $publish,
    ): RedirectResponse {
        $membership = $this->controlling($request, $enterprise);

        $request->validate([
            'photo' => ['required', 'file', 'image', 'max:12288'],
        ]);

        $file = $request->file('photo');

        if (! $file instanceof UploadedFile) {
            return back()->withErrors(['photo' => 'No photograph arrived.']);
        }

        try {
            $publish(
                $file,
                $enterprise,
                $membership->party,
                $this->account($request),
                (string) Str::uuid7(),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['photo' => $e->getMessage()]);
        }

        return back()->with('status', 'Your photograph is on the listing.');
    }

    public function withdraw(
        Request $request,
        Enterprise $enterprise,
        Media $media,
        PublishStorefrontPhoto $publish,
    ): RedirectResponse {
        $membership = $this->controlling($request, $enterprise);

        // The photograph has to belong to this business and to a party, not to
        // an officer. A route that could withdraw an officer's photograph would
        // let a business erase the evidence of its own visit.
        if (
            $media->mediable_type !== $enterprise->getMorphClass()
            || $media->mediable_id !== $enterprise->id
            || $media->kind !== Media::KIND_STOREFRONT
            || $media->uploaded_by_party_id === null
        ) {
            abort(404);
        }

        $publish->withdraw($media, $membership->party, $this->account($request));

        return back()->with('status', 'That photograph is no longer shown.');
    }

    private function account(Request $request): PortalAccount
    {
        $account = $request->user('portal');

        if (! $account instanceof PortalAccount) {
            throw new AccessDeniedHttpException('Sign in first.');
        }

        return $account;
    }

    /** The membership that controls this business, or a refusal. */
    private function controlling(Request $request, Enterprise $enterprise): PartyUser
    {
        $membership = $this->acting->forRequest($request, $this->account($request));

        if (! $membership instanceof PartyUser) {
            throw new AccessDeniedHttpException('You do not manage this business.');
        }

        $controls = PartyBusiness::query()
            ->where('enterprise_id', $enterprise->id)
            ->where('party_id', $membership->party_id)
            ->where('status', PartyBusiness::STATUS_ACTIVE)
            ->exists();

        if (! $controls) {
            throw new AccessDeniedHttpException('You do not manage this business.');
        }

        return $membership;
    }
}
