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
 *     ISO 7064 MOD 37,36 check character, which detects every single-character
 *     substitution and every transposition of adjacent characters. Without it a
 *     typo can land on another party's record, and on a platform where the code
 *     is the accountability spine that is not a validation nicety.
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
     * ISO 7064 MOD 37,36 is defined over 0-9 A-Z. The generation alphabet is a
     * subset of it, so a code is always valid input to the algorithm while
     * never being emitted with an ambiguous character in it.
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
        $body = '';

        for ($i = 0; $i < 8; $i++) {
            $body .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $this->format($body.$this->checkCharacter($body));
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
        $check = substr($normalised, 8, 1);

        return hash_equals($this->checkCharacter($body), $check);
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

        if (str_starts_with($stripped, self::PREFIX)) {
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
     * ISO 7064 MOD 37,36, the hybrid system.
     *
     * Detects every single-character substitution and every transposition of
     * two adjacent characters. Both are proven by test rather than asserted
     * here, because a check character nobody exercised is decoration.
     */
    private function checkCharacter(string $body): string
    {
        $modulus = 36;
        $product = $modulus;

        foreach (str_split($body) as $character) {
            $value = strpos(self::CHECK_ALPHABET, $character);

            if ($value === false) {
                throw new InvalidArgumentException("'{$character}' is not in the code alphabet.");
            }

            $sum = ($product % ($modulus + 1)) + $value;
            $sum %= $modulus;

            if ($sum === 0) {
                $sum = $modulus;
            }

            $product = $sum * 2;
        }

        $check = ($modulus + 1 - ($product % ($modulus + 1))) % $modulus;

        return self::CHECK_ALPHABET[$check];
    }
}
