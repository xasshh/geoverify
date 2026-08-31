<?php

declare(strict_types=1);

use App\Domain\Party\Actions\PartyCode;
use App\Domain\Party\Models\Party;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Database\Migrations\Migration;

/**
 * Repairs codes minted by the old checksum.
 *
 * The check character moved from ISO 7064 MOD 37,36 to MOD 37-2, and the
 * character itself is now held to the unambiguous alphabet so normalise() can
 * never fold it. Codes issued before that do not satisfy the new check and
 * would be refused the first time anybody typed one in.
 *
 * A party code is meant to last for life, so this is a thing to do exactly once
 * and never again: it is only safe because no code has yet been printed, read
 * down a phone or attached to an order. After Phase 2 ships, a broken code gets
 * a documented reissue with the old one kept as an alias, not a silent rewrite.
 *
 * Only codes that actually fail are touched, so running it twice changes
 * nothing, and each reissue is appended to the log with the code it replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        $codes = app(PartyCode::class);

        Party::query()->orderBy('id')->each(function (Party $party) use ($codes): void {
            if ($codes->isValid((string) $party->code)) {
                return;
            }

            $was = (string) $party->code;

            do {
                $reissued = $codes->issue();
            } while (Party::query()->where('code', $reissued)->exists());

            $party->update(['code' => $reissued]);

            VerificationEvent::record(
                $party,
                'party.code_reissued',
                null,
                ['from' => $was, 'to' => $reissued, 'reason' => 'checksum corrected to ISO 7064 MOD 37-2'],
                VerificationEvent::ACTOR_SYSTEM,
            );
        });
    }

    public function down(): void
    {
        // Deliberately irreversible. The old codes did not satisfy their own
        // checksum, so restoring them would put the register back into the
        // state this migration exists to leave.
    }
};
