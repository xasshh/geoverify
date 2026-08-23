<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\IdentityClaim;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Turns a personal identity number into something safe to keep, and makes sure
 * the number itself never survives the call.
 *
 * Under NDPA 2023 a NIN is not ours to hold. What is kept is a keyed hash, so the
 * same number recognises itself across records without being readable, and the
 * last four digits so a person can confirm which credential was checked. Four
 * digits identifies nobody on its own.
 *
 * The hash is keyed with the application key rather than being a bare digest: an
 * unkeyed hash of an eleven digit number is trivially reversible by brute force,
 * because there are only a hundred billion of them and a laptop can walk that.
 *
 * CAC registration numbers go through unchanged. They are public record and are
 * meant to be looked up.
 */
final class HashIdentityReference
{
    /**
     * @return array{token: string, last4: string|null}
     */
    public function hash(
        string $kind,
        #[SensitiveParameter] string $reference,
    ): array {
        $reference = trim($reference);

        if ($reference === '') {
            throw new InvalidArgumentException('An identity reference cannot be blank.');
        }

        if (! in_array($kind, IdentityClaim::PERSONAL_KINDS, true)) {
            // Public record. Stored as given.
            return ['token' => $reference, 'last4' => null];
        }

        $digits = preg_replace('/\D/', '', $reference) ?? '';

        if (strlen($digits) !== 11) {
            throw new InvalidArgumentException(
                'A Nigerian NIN or BVN has 11 digits. Check the number and try again.',
            );
        }

        $token = hash_hmac('sha256', $digits, $this->key());
        $last4 = substr($digits, -4);

        // Overwritten before returning, so a memory dump or a stack trace taken
        // after this point does not still contain the number.
        $digits = str_repeat("\0", 11);
        unset($digits);

        return ['token' => $token, 'last4' => $last4];
    }

    /**
     * True when a stored token was produced from this reference. Used to spot the
     * same person across enterprises without ever holding their number.
     */
    public function matches(
        string $kind,
        #[SensitiveParameter] string $reference,
        string $storedToken,
    ): bool {
        return hash_equals($storedToken, $this->hash($kind, $reference)['token']);
    }

    private function key(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new InvalidArgumentException(
                'APP_KEY is not set, so identity references cannot be hashed safely.',
            );
        }

        return $key;
    }
}
