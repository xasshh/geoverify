<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\ConsentReceipt;
use App\Domain\Registry\Models\Enterprise;

/**
 * Answers the question a receipt link asks: what did I agree to, and does it
 * still stand?
 *
 * Reached with no session, like the certificate check and for a related reason.
 * The NDPA gives a person the right to a copy of what they agreed to, and a
 * copy obtainable only by signing into an account they may no longer hold is
 * not much of a copy. The token is the whole authorisation, which is why it is
 * forty eight characters of random.
 *
 * Every field is read off the receipt rather than off the purpose it points at.
 * That is the entire reason those columns are duplicated: rendering today's
 * wording would answer "what did you agree to" with a document the person has
 * never seen, which is worse than not answering.
 *
 * What it will not disclose is anything the receipt does not itself contain. A
 * receipt names its subject and the words that were shown; it is not a way in
 * to the register behind it, so there is no phone number here, no coordinate
 * and no photograph, whatever the subject's publication state.
 */
final class ResolveConsentReceipt
{
    /**
     * Field names as the person reading them would say them.
     *
     * The scope vocabulary belongs to whichever purpose wrote the receipt, so
     * this is a courtesy rather than a closed list: anything unrecognised is
     * shown in its own words rather than dropped, because a receipt that
     * silently omitted part of what was agreed would be the one failure this
     * document cannot have.
     */
    private const FIELD_LABELS = [
        'trading_name' => 'Trading name',
        'registered_name' => 'Registered name',
        'sector' => 'What the business does',
        'sector_code' => 'What the business does',
        'ward' => 'Ward',
        'lga' => 'Local government',
        'state' => 'State',
        'opening_hours' => 'Opening hours',
        'phone' => 'Phone number',
        'email' => 'Email',
        'website' => 'Website',
    ];

    /**
     * @return array{
     *     state: string,
     *     reference?: string,
     *     purpose?: string,
     *     subject?: string|null,
     *     disclosure?: string,
     *     disclosure_version?: string,
     *     lawful_basis?: string,
     *     scope?: list<string>,
     *     agreed_by?: string|null,
     *     agreed_as?: string,
     *     agreed_on?: string,
     *     withdrawn_on?: string|null
     * }
     */
    public function __invoke(string $token): array
    {
        $receipt = ConsentReceipt::query()
            ->with(['purpose', 'subject'])
            ->where('token', $token)
            ->first();

        // One answer for a token we never issued and for one somebody typed
        // wrongly. Telling those apart would let a stranger probe the shape of
        // a real token, which is the only thing protecting every other receipt.
        if (! $receipt instanceof ConsentReceipt) {
            return ['state' => 'unknown'];
        }

        $subject = $receipt->subject;

        return [
            'state' => $this->state($receipt),
            'reference' => self::reference($receipt),
            'purpose' => $receipt->purpose->name,
            'subject' => $subject instanceof Enterprise ? $subject->trading_name : null,
            'disclosure' => $receipt->disclosure,
            'disclosure_version' => $receipt->disclosure_version,
            'lawful_basis' => str_replace('_', ' ', $receipt->lawful_basis),
            'scope' => $this->scope($receipt),
            'agreed_by' => $receipt->actor_label,
            'agreed_as' => $receipt->actor_type,
            'agreed_on' => $receipt->agreed_at->toDateString(),
            'withdrawn_on' => $receipt->withdrawn_at?->toDateString(),
        ];
    }

    /**
     * Something to quote on the phone.
     *
     * Not the token. A person reading their reference out to somebody who
     * answers a support line should not thereby hand over the credential that
     * opens the document, and an id is enough to find the row.
     */
    public static function reference(ConsentReceipt $receipt): string
    {
        return 'CR-'.str_pad((string) $receipt->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Four outcomes, and only one of them is the happy one.
     *
     * A receipt for an answer of no is still a receipt: it is the evidence that
     * the person was asked and declined, which is exactly what gets disputed
     * later by somebody claiming they were never asked at all.
     *
     * Refusal and withdrawal both carry `granted` false and are not the same
     * event, so they are told apart by what points at them: a withdrawal is the
     * row some earlier grant was superseded by. Reading it off the link rather
     * than off a column keeps the pair as one history with one direction of
     * travel, which is what the withdrawal stamp was for.
     */
    private function state(ConsentReceipt $receipt): string
    {
        if ($receipt->withdrawn()) {
            return 'withdrawn';
        }

        if ($receipt->granted) {
            return 'standing';
        }

        $supersedes = ConsentReceipt::query()
            ->where('withdrawn_by_receipt_id', $receipt->id)
            ->exists();

        return $supersedes ? 'withdrawal' : 'refused';
    }

    /** @return list<string> */
    private function scope(ConsentReceipt $receipt): array
    {
        return array_values(array_map(
            static fn (string $field): string => self::FIELD_LABELS[$field]
                ?? ucfirst(str_replace('_', ' ', $field)),
            $receipt->scope,
        ));
    }
}
