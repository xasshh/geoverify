<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Actions;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Media\Actions\PublishStorefrontPhoto;
use App\Domain\Registry\Actions\ResolveListingTier;
use App\Domain\Registry\Enums\PublicationState;
use App\Domain\Registry\Models\Enterprise;

/**
 * How complete a listing is, as the mockup's "Listing strength" card shows it.
 *
 * Five things a buyer looks for, twenty points each, and the first two still
 * missing named in words. Nothing here is a judgement of the business: it is a
 * checklist of what the business itself can do next.
 */
final class ReadListingStrength
{
    public const PRODUCTS_WANTED = 3;

    public function __construct(private readonly ResolveListingTier $tiers) {}

    /** @return array{percent: int, missing: list<string>, hint: string} */
    public function __invoke(Enterprise $enterprise): array
    {
        $enterprise->loadMissing('structure');

        $products = Product::query()
            ->where('enterprise_id', $enterprise->id)
            ->where('status', Product::STATUS_ACTIVE)
            ->withCount(['photos'])
            ->get();

        $unphotographed = $products->filter(static fn (Product $p): bool => (int) $p->getAttribute('photos_count') === 0)->count();
        $productsShort = max(0, self::PRODUCTS_WANTED - $products->count());

        $checks = [
            [PublishStorefrontPhoto::countFor($enterprise) > 0, 'add a photograph of your shop front'],
            [$productsShort === 0, $productsShort === 1 ? 'add 1 more product' : "add {$productsShort} more products"],
            [$products->isNotEmpty() && $unphotographed === 0, match (true) {
                $products->isEmpty() => 'add product photos',
                $unphotographed === 1 => 'add a photo to 1 product',
                default => "add photos to {$unphotographed} products",
            }],
            [$enterprise->publication_state === PublicationState::OptedIn, 'publish your listing'],
            [$this->tiers->forOrigin((string) $enterprise->structure->origin, (string) $enterprise->structure->status) !== 'listed', 'get your location verified'],
        ];

        $missing = array_values(array_map(
            static fn (array $c): string => $c[1],
            array_filter($checks, static fn (array $c): bool => ! $c[0]),
        ));

        $percent = (count($checks) - count($missing)) * 20;

        return [
            'percent' => $percent,
            'missing' => $missing,
            'hint' => $missing === []
                ? 'Everything a buyer looks for is here.'
                : ucfirst(implode(' and ', array_slice($missing, 0, 2))).' to reach '.min(100, $percent + 20 * min(2, count($missing))).'%.',
        ];
    }
}
