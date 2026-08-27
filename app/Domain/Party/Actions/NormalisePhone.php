<?php

declare(strict_types=1);

namespace App\Domain\Party\Actions;

use InvalidArgumentException;

/**
 * A Nigerian mobile number, in one form.
 *
 * The phone is the credential here, so two spellings of one number are two
 * accounts for one person: they register as 0803, come back as +234 803, and
 * find an empty dashboard where their shop used to be. Normalising on the way
 * in is what makes the unique index on the column mean what it says.
 *
 * Stored as E.164 because that is what a gateway wants and what a second
 * country would need.
 */
final class NormalisePhone
{
    private const COUNTRY_CODE = '234';

    /** Nigerian mobile prefixes are 070, 080, 081, 090, 091 and the 0 is local. */
    private const NATIONAL_LENGTH = 10;

    public function __invoke(string $input): string
    {
        $digits = (string) preg_replace('/\D/', '', $input);

        if ($digits === '') {
            throw new InvalidArgumentException('A phone number is required.');
        }

        // 2348031234567 or 08031234567 or 8031234567, all the same number.
        if (str_starts_with($digits, self::COUNTRY_CODE)) {
            $national = substr($digits, strlen(self::COUNTRY_CODE));
        } elseif (str_starts_with($digits, '0')) {
            $national = substr($digits, 1);
        } else {
            $national = $digits;
        }

        if (strlen($national) !== self::NATIONAL_LENGTH) {
            throw new InvalidArgumentException(
                'That does not look like a Nigerian mobile number.',
            );
        }

        return '+'.self::COUNTRY_CODE.$national;
    }

    /**
     * The form shown back to a person, which is the one they wrote down.
     *
     * 0803 123 4567 rather than +2348031234567: the second is correct and the
     * first is what is on their SIM pack.
     */
    public function forDisplay(string $e164): string
    {
        $digits = (string) preg_replace('/\D/', '', $e164);
        $national = str_starts_with($digits, self::COUNTRY_CODE)
            ? substr($digits, strlen(self::COUNTRY_CODE))
            : $digits;

        if (strlen($national) !== self::NATIONAL_LENGTH) {
            return $e164;
        }

        return sprintf('0%s %s %s', substr($national, 0, 3), substr($national, 3, 3), substr($national, 6));
    }

    /** Masked, for saying which number a code went to without printing it. */
    public function masked(string $e164): string
    {
        $shown = $this->forDisplay($e164);

        return substr($shown, 0, 4).' ••• '.substr($shown, -4);
    }
}
