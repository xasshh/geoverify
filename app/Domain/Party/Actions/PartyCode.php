<?php

declare(strict_types=1);

namespace App\Domain\Party\Actions;

use InvalidArgumentException;
use Random\RandomException;

/**
 * The permanent party code: NBD-XXXX-XXXX-C.
 *
 * One party, one code, for life. It follows the party across listings,
 * verification orders, payments, documents and disputes, which makes it the
 * spine of accountability on the platform and the thing a person will read
 * down a phone line to somebody writing it on paper.
 *
 * Three properties, in the order they matter:
 *
 *  1. **It encodes nothing.** Not a date, not a location, not a sequence. A
 *     code that revealed how many parties exist would tell a competitor the
 *     size of the register, and a code that revealed when a party joined would
 *     tell a counterparty how new they are. Both are inferences we have no
 *     business handing out.
 *
 *  2. **It is transcribable.** Uppercase, grouped in fours, and drawn from an
 *     alphabet with the confusable characters removed: no O beside 0, no I or L
 *     beside 1. Reading it aloud should not require saying "as in November".
 *
 *  3. **A mistyped code fails rather than resolving.** The last character is an
 *     ISO 7064 MOD 37-2 check character, which detects every single-character
 *     substitution and every transposition of adjacent characters. Without it a
 *     typo can land on another party's record, and on a platform where the code
 *     is the accountability spine that is not a validation nicety.
 *
 * The third property was claimed before it was true. This used MOD 37,36, the
 * hybrid, which lets roughly one substitution in 389 through, and it drew the
 * check character from an alphabet wider than the one normalise() folds into,
 * so about one issued code in twelve was rejected by the system that minted it.
 * Both are fixed here and both are now proven by test.
 */
final class PartyCode
{
    public const PREFIX = 'NBD';

    /**
     * The generation alphabet: 31 characters.
     *
     * Full uppercase alphanumeric less the confusable pairs the brief names:
     * 0 and O, 1 and I and L. Nothing is ever generated containing them, so a
     * code that arrives with one has been mistyped or misread, and normalise()
     * folds it back before the checksum gets its say.
     */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * The checksum alphabet: 36 characters, positionally significant.
     *
     * ISO 7064 MOD 37-2 is defined over 0-9 A-Z plus a 37th symbol. The
     * generation alphabet is a subset, so a code is always valid input to the
     * algorithm while never being emitted with an ambiguous character in it,
     * check character included.
     */
    private const CHECK_ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** Characters a person plausibly types for one we never emit. */
    private const CONFUSIONS = ['O' => '0', 'I' => '1', 'L' => '1'];

    /**
     * A fresh code. Random, not sequential.
     *
     * @throws RandomException
     */
    public function issue(): string
    {
        while (true) {
            $body = '';

            for ($i = 0; $i < 8; $i++) {
                $body .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $check = $this->checkValue($body);
            $character = $check === 36 ? '*' : self::CHECK_ALPHABET[$check];

            // The check character has to survive normalise() unchanged, and
            // normalise folds O to 0 and I and L to 1. Drawing it from the full
            // 36 meant roughly one issued code in twelve had a check character
            // that was folded on the way back in and then disagreed with itself:
            // the code was rejected by the system that had just minted it.
            //
            // So the check is held to the same unambiguous alphabet as the body
            // and another body is drawn when it lands outside. MOD 37-2 still
            // runs over the full 37 values, so the detection properties are
            // untouched; what changes is only which bodies we are willing to
            // issue. About one in six is passed over, and since the body is
            // random and encodes nothing, neither does the choosing.
            if (! str_contains(self::ALPHABET, $character)) {
                continue;
            }

            return $this->format($body.$character);
        }
    }

    /**
     * Whether a code is well formed and its check character agrees.
     *
     * Answers false rather than throwing: this is called on user input, and a
     * mistyped code is an ordinary event rather than an exceptional one.
     */
    public function isValid(string $code): bool
    {
        $normalised = $this->normalise($code);

        if (! preg_match('/^[0-9A-Z]{9}$/', $normalised)) {
            return false;
        }

        $body = substr($normalised, 0, 8);
        $check = strpos(self::CHECK_ALPHABET, substr($normalised, 8, 1));

        return $check !== false && $this->checkValue($body) === $check;
    }

    /**
     * Input as the algorithm sees it: upper case, punctuation gone, the
     * confusable characters folded back to what we would have emitted.
     *
     * A person reading "NBD-7K4M-2XP9-R" off a receipt over the phone is the
     * case this exists for.
     */
    public function normalise(string $code): string
    {
        $upper = mb_strtoupper(trim($code));
        $stripped = (string) preg_replace('/[^0-9A-Z]/', '', $upper);

        // Length-aware, because N, B and D are all in the generation alphabet
        // and roughly one body in thirty thousand begins with the letters NBD.
        // Stripping on the prefix alone ate three characters out of those and
        // made this function non-idempotent: normalise(normalise($x)) differed
        // from normalise($x) for exactly the codes it mattered for.
        if (strlen($stripped) === strlen(self::PREFIX) + 9 && str_starts_with($stripped, self::PREFIX)) {
            $stripped = substr($stripped, strlen(self::PREFIX));
        }

        return strtr($stripped, self::CONFUSIONS);
    }

    /** NBD-XXXX-XXXX-C, the form a person ever sees. */
    public function format(string $nineCharacters): string
    {
        if (strlen($nineCharacters) !== 9) {
            throw new InvalidArgumentException('A party code body is nine characters.');
        }

        return sprintf(
            '%s-%s-%s-%s',
            self::PREFIX,
            substr($nineCharacters, 0, 4),
            substr($nineCharacters, 4, 4),
            substr($nineCharacters, 8, 1),
        );
    }

    /**
     * ISO 7064 MOD 37-2, the pure system.
     *
     * Detects every single-character substitution and every transposition of
     * two adjacent characters. Both are proven by test rather than asserted
     * here, because a check character nobody exercised is decoration.
     *
     * This replaced MOD 37,36, the hybrid, which did not actually hold the
     * first of those properties. The hybrid compresses a mod 37 state into a
     * mod 36 one on every character, so the intermediate values 0 and 36
     * collapse together and the difference between two bodies can vanish
     * mid-string. Measured over 84,000 single-character substitutions it let
     * roughly one in 389 through, which for the identifier this platform hangs
     * accountability on is one in 389 typos landing on somebody else's record.
     *
     * The pure system carries a full mod 37 state end to end. 37 is prime and
     * the radix 2 is a unit in it, so every position's weight is invertible:
     * changing any one character always changes the result, and adjacent
     * weights differ, so a swap always does too.
     */
    private function checkValue(string $body): int
    {
        $p = 0;

        foreach (str_split($body) as $character) {
            $value = strpos(self::CHECK_ALPHABET, $character);

            if ($value === false) {
                throw new InvalidArgumentException("'{$character}' is not in the code alphabet.");
            }

            $p = (($p + $value) * 2) % 37;
        }

        return (38 - $p) % 37;
    }
}
