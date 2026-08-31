<?php

declare(strict_types=1);

namespace App\Domain\Registry\Enums;

/**
 * Whether a listing may be shown outside this system.
 *
 * Three states, and the third is the reason there are not two. `private` means
 * nobody has been asked. `withheld` means somebody was asked and said no. A
 * later feature that treated those alike would quietly re-ask the people who
 * had already declined, which is the kind of thing that ends a data protection
 * conversation badly.
 */
enum PublicationState: string
{
    case Private = 'private';
    case OptedIn = 'opted_in';
    case Withheld = 'withheld';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Not published',
            self::OptedIn => 'Published',
            self::Withheld => 'Kept off the register',
        };
    }

    /** The only state a publication query may ever return. */
    public function publishable(): bool
    {
        return $this === self::OptedIn;
    }

    public function explanation(): string
    {
        return match ($this) {
            self::Private => 'Your listing is on the register but is not shown to anyone outside it. Nobody has asked you about this yet.',
            self::OptedIn => 'Your listing may be shown publicly. You can withdraw this at any time and it takes effect immediately.',
            self::Withheld => 'You have asked us not to publish this listing. We will not ask again unless you change it here.',
        };
    }
}
