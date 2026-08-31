<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Enums;

/**
 * The kinds of people who have to be squared before officers walk.
 *
 * Grouped this way because it is how the work is actually sequenced: the state
 * government and the agency are engaged in an office weeks ahead, the
 * traditional authority is visited in person days ahead, and the community
 * group is spoken to the morning of. A flat list of contacts loses that order.
 */
enum StakeholderCategory: string
{
    case GovernmentAgency = 'government_agency';
    case StateGovernment = 'state_government';
    case TraditionalAuthority = 'traditional_authority';
    case Landlord = 'landlord';
    case InstitutionHead = 'institution_head';
    case Media = 'media';
    case CommunityGroup = 'community_group';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::GovernmentAgency => 'Government agency',
            self::StateGovernment => 'State government',
            self::TraditionalAuthority => 'Traditional authority',
            self::Landlord => 'Landlord',
            self::InstitutionHead => 'Institution head',
            self::Media => 'Media',
            self::CommunityGroup => 'Community group',
            self::Other => 'Other',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(static fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
