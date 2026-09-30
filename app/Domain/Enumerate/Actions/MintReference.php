<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Actions;

use Illuminate\Support\Carbon;

/**
 * VRF-20260917-KQ83P2 and FND-20260923-7H2Q: the date a person will recognise,
 * then random characters from an alphabet without the ones that are misread
 * over the phone (0 and O, 1 and I). Unique by index; the caller retries on
 * the vanishingly rare collision rather than this reading the table first.
 */
final class MintReference
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function __invoke(string $prefix, int $length = 6): string
    {
        $tail = '';

        for ($i = 0; $i < $length; $i++) {
            $tail .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return sprintf('%s-%s-%s', $prefix, Carbon::now(config('app.timezone'))->format('Ymd'), $tail);
    }
}
