<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\ConsentReceipt;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Somebody takes back what they agreed to.
 *
 * Two rows afterwards, not one edited row. The original receipt keeps saying
 * exactly what it always said and gains a stamp pointing at the withdrawal; the
 * withdrawal is a receipt in its own right, with `granted` false and the same
 * disclosure, so the pair reads as a history rather than as a correction.
 *
 * The database enforces that: the append only trigger on `consent_receipts`
 * permits exactly two columns to change and refuses a second withdrawal of the
 * same receipt outright.
 */
final class WithdrawConsentReceipt
{
    public function __construct(private readonly RecordConsentReceipt $record) {}

    public function __invoke(
        ConsentReceipt $receipt,
        string $actorType,
        ?int $actorId = null,
        ?string $actorLabel = null,
    ): ConsentReceipt {
        return DB::transaction(function () use ($receipt, $actorType, $actorId, $actorLabel): ConsentReceipt {
            $subject = $receipt->subject;

            $withdrawal = ($this->record)(
                $subject,
                $receipt->purpose->key,
                false,
                $actorType,
                $actorId,
                $actorLabel,
                $receipt->scope,
                $receipt->language,
            );

            $receipt->update([
                'withdrawn_at' => Carbon::now(config('app.timezone')),
                'withdrawn_by_receipt_id' => $withdrawal->id,
            ]);

            VerificationEvent::record($subject, 'consent.withdrawn', null, [
                'purpose' => $receipt->purpose->key,
                'withdrew' => $receipt->token,
                'receipt_token' => $withdrawal->token,
            ], $actorType);

            return $withdrawal;
        });
    }
}
