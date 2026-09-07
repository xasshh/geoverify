<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\ConsentReceipt;
use App\Domain\Identity\Models\ProcessingPurpose;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes down what somebody agreed to, in the words they were shown.
 *
 * The disclosure is copied in rather than referenced. A receipt that pointed at
 * a template would answer "what did they agree to" with whatever that template
 * says today, which for a document whose purpose is to survive an argument
 * years later is the same as not answering.
 *
 * `scope` is the list of fields the agreement actually covered. It exists so a
 * later, wider form cannot claim retrospective coverage: if publication grows to
 * include a photograph next year, the receipts from this year still say plainly
 * that a photograph was not part of what anybody agreed to.
 */
final class RecordConsentReceipt
{
    /**
     * @param  list<string>  $scope
     */
    public function __invoke(
        Model $subject,
        string $purposeKey,
        bool $granted,
        string $actorType,
        ?int $actorId = null,
        ?string $actorLabel = null,
        array $scope = [],
        ?string $language = null,
    ): ConsentReceipt {
        $purpose = ProcessingPurpose::named($purposeKey);

        return DB::transaction(function () use (
            $subject, $purpose, $granted, $actorType, $actorId, $actorLabel, $scope, $language
        ): ConsentReceipt {
            $receipt = ConsentReceipt::query()->create([
                'token' => ConsentReceipt::mintToken(),
                'processing_purpose_id' => $purpose->id,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'actor_label' => $actorLabel,
                'granted' => $granted,
                'disclosure' => $purpose->disclosure,
                'disclosure_version' => $purpose->disclosure_version,
                'lawful_basis' => $purpose->lawful_basis,
                'language' => $language ?? 'en',
                'scope' => $scope,
                'agreed_at' => Carbon::now(config('app.timezone')),
            ]);

            VerificationEvent::record(
                $subject,
                $granted ? 'consent.granted' : 'consent.refused',
                null,
                [
                    'purpose' => $purpose->key,
                    'lawful_basis' => $purpose->lawful_basis,
                    'disclosure_version' => $purpose->disclosure_version,
                    'receipt_token' => $receipt->token,
                ],
                $actorType,
            );

            return $receipt;
        });
    }
}
