<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Registry;

/**
 * A company as CAC records it, reduced to what a report prints.
 *
 * Directors carry a name and a role and nothing else. CAC's answer includes
 * their phone numbers and genders; a driver drops those before building this,
 * so they are never stored or shown, whatever a later screen asks for.
 */
final readonly class CompanyRecord
{
    /**
     * @param  list<array{name: string, role: string}>  $directors
     */
    public function __construct(
        public string $name,
        public string $rcNumber,
        public string $companyType,
        public string $status,
        public ?string $incorporatedOn,
        public ?string $address,
        public array $directors,
    ) {}

    /** @return array<string, mixed> */
    public function toFacts(): array
    {
        return [
            'name' => $this->name,
            'rcNumber' => $this->rcNumber,
            'companyType' => $this->companyType,
            'status' => $this->status,
            'incorporatedOn' => $this->incorporatedOn,
            'address' => $this->address,
            'directors' => $this->directors,
        ];
    }
}
