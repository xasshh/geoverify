<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Catalogue\Actions\ManageBusinessProfile;
use App\Domain\Catalogue\Models\BusinessProfile;
use App\Domain\Registry\Models\Enterprise;
use App\Http\Controllers\Portal\Concerns\ActsForBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** Opening hours, delivery and the address to be found by: the owner's say. */
final class BusinessProfileController
{
    use ActsForBusiness;

    public function show(Request $request, Enterprise $enterprise): Response
    {
        $membership = $this->controlling($request, $enterprise);
        $profile = BusinessProfile::query()->where('enterprise_id', $enterprise->id)->first();

        return Inertia::render('portal/BusinessProfile', [
            'business' => ['id' => $enterprise->id, 'name' => $enterprise->trading_name],
            'profile' => [
                'hours' => $profile->weekly_hours ?? null,
                'delivers' => $profile?->delivers,
                'address' => $profile?->street_address,
            ],
            'canEdit' => $membership->role->proposesChanges(),
        ]);
    }

    public function save(Request $request, Enterprise $enterprise, ManageBusinessProfile $profiles): RedirectResponse
    {
        $membership = $this->editing($request, $enterprise);
        $input = $request->validate([
            'hours' => ['array'],
            'hours.*.opens' => ['nullable', 'string', 'max:5'],
            'hours.*.closes' => ['nullable', 'string', 'max:5'],
            'delivers' => ['nullable', 'boolean'],
            'address' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $profiles->save(
                $enterprise,
                $membership,
                $input['hours'] ?? [],
                array_key_exists('delivers', $input) && $input['delivers'] !== null ? (bool) $input['delivers'] : null,
                $input['address'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['hours' => $e->getMessage()]);
        }

        return back()->with('status', 'Saved. Your listing shows it now.');
    }
}
