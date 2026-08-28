<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Party\Actions\ActingParty;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Actions\NearbyFootprints;
use App\Domain\Registry\Actions\RegisterBusiness;
use App\Domain\Registry\Actions\ResolveAdminHierarchy;
use App\Domain\Registry\Models\BusinessRegistrationDraft;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Putting a business on the register that no officer has reached.
 *
 * Three steps, and progress is kept on the server after each one. That is not a
 * convenience: this form is filled in on a handset, on mobile data, frequently
 * while standing in the shop it describes, and a dropped connection on the last
 * question must not cost the first two. Every step is a normal form post, so
 * the whole thing works with an unreliable connection and no client state.
 *
 * Everything derived is derived on GET from what is saved, rather than passed
 * forward between steps. A person who reloads, or comes back an hour later,
 * sees exactly what they left, and the ward we resolved is resolved from the
 * point every time rather than carried in a hidden field.
 */
final class RegisterBusinessController
{
    public function __construct(
        private readonly ActingParty $acting,
        private readonly ResolveAdminHierarchy $hierarchy,
        private readonly NearbyFootprints $footprints,
        private readonly RegisterBusiness $registrations,
    ) {}

    public function show(Request $request): Response
    {
        $membership = $this->membership($request);
        $draft = $this->draftFor($membership);

        $place = null;
        $buildings = [];
        $duplicates = [];

        if ($draft->hasPlace()) {
            $lng = (float) $draft->answer('longitude');
            $lat = (float) $draft->answer('latitude');

            // Resolved on every render, from the point. Never stored in the
            // draft and never accepted from the browser: a ward is a fact about
            // a coordinate, and a draft that sat for a week must not carry a
            // stale one into the register.
            $resolved = $this->hierarchy->forPoint($lng, $lat);
            $described = $this->hierarchy->describe($resolved);

            $place = [
                'latitude' => $lat,
                'longitude' => $lng,
                'ward' => $described['ward'] ?? null,
                'lga' => $described['lga'] ?? null,
                'state' => $described['state'] ?? null,
                'covered' => $resolved['state_id'] !== null,
                'footprintId' => $draft->answer('external_footprint_id'),
            ];

            $buildings = $this->footprints->around($lng, $lat);

            $name = (string) $draft->answer('trading_name', '');

            if ($name !== '') {
                $duplicates = $this->registrations->possibleDuplicates($lng, $lat, $name);
            }
        }

        return Inertia::render('portal/RegisterBusiness', [
            'step' => $draft->step,
            'answers' => [
                'tradingName' => $draft->answer('trading_name', ''),
                'structureType' => $draft->answer('structure_type', 'shophouse'),
                'phone' => $draft->answer('phone', ''),
            ],
            'place' => $place,
            'buildings' => $buildings,
            'duplicates' => $duplicates,
            'structureTypes' => $this->structureTypes(),
            'party' => [
                'code' => $membership->party?->code,
                'displayName' => $membership->party?->display_name,
            ],
        ]);
    }

    /** Step one: what it is called. */
    public function saveName(Request $request): RedirectResponse
    {
        $membership = $this->membership($request);
        $draft = $this->draftFor($membership);

        $data = $request->validate([
            'trading_name' => ['required', 'string', 'min:2', 'max:160'],
            'structure_type' => ['required', 'string', 'in:'.implode(',', array_column($this->structureTypes(), 'value'))],
        ]);

        $draft->remember($data);
        $draft->step = BusinessRegistrationDraft::STEP_PLACE;
        $draft->save();

        return redirect()->route('portal.register-business');
    }

    /**
     * Step two: where it is.
     *
     * The request carries a point and nothing else about place. A ward, LGA or
     * state arriving from the browser is refused outright rather than ignored,
     * because silently dropping it would let a client believe it had been
     * accepted, and the next version of that client would rely on it.
     *
     * Posted twice, deliberately. The first press reads the device's position
     * and stays on this step, because the position is where the phone thinks
     * you are and the question still to answer is which building is yours. The
     * second press is the answer to that question, and only it moves on.
     */
    public function savePlace(Request $request): RedirectResponse
    {
        $membership = $this->membership($request);
        $draft = $this->draftFor($membership);

        foreach (['ward_id', 'lga_id', 'state_id', 'ward', 'lga', 'state'] as $forbidden) {
            if ($request->has($forbidden)) {
                throw ValidationException::withMessages([
                    'location' => 'The administrative area is resolved from the location by the server and cannot be supplied.',
                ]);
            }
        }

        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'external_footprint_id' => ['nullable', 'integer', 'exists:external_footprints,id'],
            'confirm' => ['nullable', 'boolean'],
        ]);

        $confirmed = (bool) ($data['confirm'] ?? false);
        unset($data['confirm']);

        $draft->remember($data);

        if ($confirmed) {
            $draft->step = BusinessRegistrationDraft::STEP_CONFIRM;
        }

        $draft->save();

        return redirect()->route('portal.register-business');
    }

    /** Back a step, without losing anything. */
    public function back(Request $request): RedirectResponse
    {
        $membership = $this->membership($request);
        $draft = $this->draftFor($membership);

        $draft->step = match ($draft->step) {
            BusinessRegistrationDraft::STEP_CONFIRM => BusinessRegistrationDraft::STEP_PLACE,
            default => BusinessRegistrationDraft::STEP_NAME,
        };
        $draft->save();

        return redirect()->route('portal.register-business');
    }

    public function submit(Request $request): RedirectResponse
    {
        $membership = $this->membership($request);

        if (! $membership->role->claims()) {
            throw new AccessDeniedHttpException('Your access to this party does not include adding businesses.');
        }

        $draft = $this->draftFor($membership);

        if (! $draft->hasPlace()) {
            throw ValidationException::withMessages([
                'location' => 'We still need to know where this business is.',
            ]);
        }

        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:32'],
            'sector_code' => ['nullable', 'string', 'exists:isic_classes,code'],
        ]);

        $draft->remember($data);
        $draft->save();

        try {
            $enterprise = $this->registrations->register($membership->party, [
                'trading_name' => (string) $draft->answer('trading_name'),
                'sector_code' => $data['sector_code'] ?? null,
                'structure_type' => (string) $draft->answer('structure_type', 'shophouse'),
                'longitude' => (float) $draft->answer('longitude'),
                'latitude' => (float) $draft->answer('latitude'),
                'external_footprint_id' => $draft->answer('external_footprint_id') === null
                    ? null
                    : (int) $draft->answer('external_footprint_id'),
                'phone' => $data['phone'] ?? null,
                'accuracy_m' => $draft->answer('accuracy_m') === null ? null : (float) $draft->answer('accuracy_m'),
            ]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['location' => $e->getMessage()]);
        }

        $draft->forceFill([
            'completed_at' => now(),
            'enterprise_id' => $enterprise->id,
        ])->save();

        return redirect()->route('portal.listing', $enterprise->id)
            ->with('status', 'Your business is on the register.');
    }

    /**
     * The kinds of place a business trades from, in this market.
     *
     * The same vocabulary the field client uses, so a self-registered record and
     * a captured one describe the world the same way.
     *
     * @return list<array{value: string, label: string}>
     */
    private function structureTypes(): array
    {
        return [
            ['value' => 'shophouse', 'label' => 'A shop in a building'],
            ['value' => 'kiosk', 'label' => 'A kiosk or container'],
            ['value' => 'market_stall', 'label' => 'A stall in a market'],
            ['value' => 'commercial_block', 'label' => 'A unit in a commercial block'],
            ['value' => 'residential', 'label' => 'Run from a home'],
            ['value' => 'industrial', 'label' => 'A workshop or factory'],
        ];
    }

    private function draftFor(PartyUser $membership): BusinessRegistrationDraft
    {
        $draft = BusinessRegistrationDraft::query()
            ->where('party_id', $membership->party_id)
            ->whereNull('completed_at')
            ->first();

        if ($draft instanceof BusinessRegistrationDraft) {
            return $draft;
        }

        return BusinessRegistrationDraft::query()->create([
            'party_id' => $membership->party_id,
            'portal_account_id' => $membership->portal_account_id,
            'step' => BusinessRegistrationDraft::STEP_NAME,
            'payload' => [],
        ]);
    }

    private function membership(Request $request): PartyUser
    {
        $account = Auth::guard('portal')->user();

        if (! $account instanceof PortalAccount) {
            throw new AccessDeniedHttpException('Not signed in.');
        }

        $membership = $this->acting->forRequest($request, $account);

        if (! $membership instanceof PartyUser) {
            throw new AccessDeniedHttpException('Choose which business you are acting for.');
        }

        return $membership;
    }
}
