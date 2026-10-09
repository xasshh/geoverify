<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Registry;

use RuntimeException;

/** The provider did not answer. Not a finding about the business. */
final class RegistryUnavailable extends RuntimeException
{
    /** Said to the person searching, word for word: the provider does not search by name. */
    public const NAME_SEARCH_OFF = 'Search by business name is not available yet. Search by RC or BN number instead.';

    /** The message a person searching may be shown, or null for the generic one. */
    public function forPeople(): ?string
    {
        return $this->getMessage() === self::NAME_SEARCH_OFF ? self::NAME_SEARCH_OFF : null;
    }
}
