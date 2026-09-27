<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Actions;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Media\Actions\StoreMediaFile;
use App\Domain\Media\Models\Media;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A business keeps its own catalogue.
 *
 * Control of the business, and a role that may change what it says, are
 * checked by the caller. Every change is recorded against the business as the
 * party's act. Taking a product down withdraws it and keeps the row.
 */
final class ManageCatalogue
{
    private const PHOTO_BYTES = 12 * 1024 * 1024;

    private const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/heic', 'image/webp'];

    public const MAX_PRODUCTS = 200;

    public function __construct(private readonly StoreMediaFile $files) {}

    /**
     * @param  array{name: string, unit?: string|null, price_naira?: int|null, description?: string|null, status?: string|null}  $input
     */
    public function save(Enterprise $enterprise, Party $party, array $input, ?Product $product = null): Product
    {
        if ($product === null && Product::query()
            ->where('enterprise_id', $enterprise->id)
            ->where('status', '<>', Product::STATUS_WITHDRAWN)
            ->count() >= self::MAX_PRODUCTS) {
            throw new RuntimeException(sprintf('A listing can hold %d products. Take one down before adding another.', self::MAX_PRODUCTS));
        }

        $product ??= new Product([
            'enterprise_id' => $enterprise->id,
            'position' => (int) Product::query()->where('enterprise_id', $enterprise->id)->max('position') + 1,
        ]);

        $product->fill([
            'party_id' => $party->id,
            'name' => $input['name'],
            'unit' => $input['unit'] ?? null,
            'price_minor' => ($input['price_naira'] ?? null) === null ? null : (int) $input['price_naira'] * 100,
            'description' => $input['description'] ?? null,
            'status' => $input['status'] ?? Product::STATUS_ACTIVE,
        ])->save();

        VerificationEvent::recordForParty($enterprise, 'catalogue.product_saved', $party, ['product_id' => $product->id]);

        return $product;
    }

    public function withdraw(Product $product, Party $party): void
    {
        $product->forceFill(['status' => Product::STATUS_WITHDRAWN])->save();

        VerificationEvent::recordForParty($product->enterprise, 'catalogue.product_withdrawn', $party, ['product_id' => $product->id]);
    }

    public function addPhoto(Product $product, UploadedFile $file, Party $party, PortalAccount $uploader): Media
    {
        if (! in_array($file->getClientMimeType(), self::PHOTO_TYPES, true)) {
            throw new RuntimeException('Upload a photograph: JPEG, PNG, HEIC or WebP.');
        }

        if ($product->photos()->count() >= Product::MAX_PHOTOS) {
            throw new RuntimeException(sprintf('A product can show %d photographs.', Product::MAX_PHOTOS));
        }

        $media = $this->files->put($file, $product, Media::KIND_PRODUCT, (string) Str::uuid7(), [
            'uploaded_by_party_id' => $party->id,
            'uploaded_by_account_id' => $uploader->id,
        ], self::PHOTO_BYTES);

        VerificationEvent::recordForParty($product->enterprise, 'catalogue.photo_added', $party, [
            'product_id' => $product->id,
            'sha256' => $media->sha256,
        ]);

        return $media;
    }

    public function withdrawPhoto(Product $product, Media $media, Party $party): void
    {
        // The photograph must be this product's, and a party's. An officer's
        // photograph can never be reached from here.
        if ($media->mediable_type !== $product->getMorphClass()
            || $media->mediable_id !== $product->id
            || $media->kind !== Media::KIND_PRODUCT
            || $media->uploaded_by_party_id === null) {
            throw new RuntimeException('That photograph is not part of this product.');
        }

        $media->update(['status' => Media::STATUS_WITHDRAWN]);

        VerificationEvent::recordForParty($product->enterprise, 'catalogue.photo_withdrawn', $party, ['product_id' => $product->id]);
    }
}
