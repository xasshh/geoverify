<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Actions;

use App\Domain\Catalogue\Models\BusinessProfile;
use App\Domain\Party\Models\PartyUser;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Verification\Models\VerificationEvent;
use RuntimeException;

/**
 * The owner states their hours, whether they deliver, and the address they
 * want found by. Checked here so the directory's "Open now" can trust what it
 * reads: every day is either closed or a pair of 24 hour times, opening before
 * closing.
 */
final class ManageBusinessProfile
{
    /** @param  array<string, array{opens?: string|null, closes?: string|null}|null>  $hours */
    public function save(Enterprise $enterprise, PartyUser $membership, array $hours, ?bool $delivers, ?string $streetAddress): BusinessProfile
    {
        $clean = [];

        foreach (BusinessProfile::DAYS as $day) {
            $slot = $hours[$day] ?? null;
            $opens = is_array($slot) ? trim((string) ($slot['opens'] ?? '')) : '';
            $closes = is_array($slot) ? trim((string) ($slot['closes'] ?? '')) : '';

            if ($opens === '' && $closes === '') {
                $clean[$day] = null;

                continue;
            }

            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $opens) !== 1 || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $closes) !== 1) {
                throw new RuntimeException('Give opening and closing times as hours and minutes, for example 08:00 and 18:00.');
            }

            if ($closes <= $opens) {
                throw new RuntimeException('A day closes after it opens. For a late night, close at 23:59.');
            }

            $clean[$day] = ['opens' => $opens, 'closes' => $closes];
        }

        $address = $streetAddress === null ? null : trim($streetAddress);

        $profile = BusinessProfile::query()->updateOrCreate(
            ['enterprise_id' => $enterprise->id],
            [
                'party_id' => $membership->party_id,
                'weekly_hours' => array_filter($clean) === [] ? null : $clean,
                'delivers' => $delivers,
                'street_address' => $address === '' ? null : mb_substr((string) $address, 0, 200),
                'updated_by' => $membership->portal_account_id,
            ],
        );

        VerificationEvent::recordForParty($enterprise, 'business_profile.saved', $membership->party()->firstOrFail(), [
            'delivers' => $delivers,
            'days_open' => count(array_filter($clean)),
            'address_published' => $profile->street_address !== null,
        ]);

        return $profile;
    }
}
