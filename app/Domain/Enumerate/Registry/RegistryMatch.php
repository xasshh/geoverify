<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Registry;

/** One candidate in the search results. */
final readonly class RegistryMatch
{
    public function __construct(
        public string $name,
        public string $rcNumber,
        public string $companyType,
        /** Active, Inactive, or null when the search does not say. */
        public ?string $status = null,
        /** Where the register places it, as far as the search says. */
        public ?string $place = null,
    ) {}

    /** @return array{name: string, rcNumber: string, companyType: string, status: string|null, place: string|null} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'rcNumber' => $this->rcNumber,
            'companyType' => $this->companyType,
            'status' => $this->status,
            'place' => $this->place,
        ];
    }
}
