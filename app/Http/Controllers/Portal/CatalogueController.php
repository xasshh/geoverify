<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Catalogue\Actions\ManageCatalogue;
use App\Domain\Catalogue\Actions\ReadListingStrength;
use App\Domain\Catalogue\Models\Product;
use App\Domain\Media\Models\Media;
use App\Domain\Registry\Models\Enterprise;
use App\Http\Controllers\Portal\Concerns\ActsForBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Listings: what the business sells, with its own photographs.
 *
 * Shown publicly on the business's profile only once the listing itself is
 * published; until then this is a draft only the business can see.
 */
final class CatalogueController
{
    use ActsForBusiness;

    public function __construct(private readonly ManageCatalogue $catalogue) {}

    public function index(Request $request, Enterprise $enterprise, ReadListingStrength $strength): Response
    {
        $membership = $this->controlling($request, $enterprise);

        $products = Product::query()
            ->where('enterprise_id', $enterprise->id)
            ->where('status', '<>', Product::STATUS_WITHDRAWN)
            ->with('photos')
            ->orderBy('position')
            ->get();

        return Inertia::render('portal/Listings', [
            'business' => [
                'id' => $enterprise->id,
                'name' => $enterprise->trading_name,
                'published' => $enterprise->publication_state->value === 'opted_in',
            ],
            'canEdit' => $membership->role->proposesChanges(),
            'strength' => $strength($enterprise),
            'products' => $products->map(static fn (Product $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'unit' => $p->unit,
                'priceNaira' => $p->price_minor === null ? null : intdiv($p->price_minor, 100),
                'description' => $p->description,
                'status' => $p->status,
                'photos' => $p->photos->map(static fn (Media $m): array => [
                    'id' => $m->id,
                    'url' => $m->temporaryUrl(30),
                ])->all(),
            ])->all(),
            'maxPhotos' => Product::MAX_PHOTOS,
        ]);
    }

    public function store(Request $request, Enterprise $enterprise): RedirectResponse
    {
        $membership = $this->editing($request, $enterprise);

        try {
            $this->catalogue->save($enterprise, $membership->party, $this->validated($request));
        } catch (RuntimeException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return back()->with('status', 'Product added.');
    }

    public function update(Request $request, Enterprise $enterprise, Product $product): RedirectResponse
    {
        $membership = $this->editing($request, $enterprise);
        $this->belongs($enterprise, $product);

        $this->catalogue->save($enterprise, $membership->party, $this->validated($request), $product);

        return back()->with('status', 'Saved.');
    }

    public function withdraw(Request $request, Enterprise $enterprise, Product $product): RedirectResponse
    {
        $membership = $this->editing($request, $enterprise);
        $this->belongs($enterprise, $product);

        $this->catalogue->withdraw($product, $membership->party);

        return back()->with('status', 'Product taken down.');
    }

    public function addPhoto(Request $request, Enterprise $enterprise, Product $product): RedirectResponse
    {
        $membership = $this->editing($request, $enterprise);
        $this->belongs($enterprise, $product);

        $request->validate(['photo' => ['required', 'file', 'image', 'max:12288']]);
        $file = $request->file('photo');
        abort_unless($file instanceof UploadedFile, 422);

        try {
            $this->catalogue->addPhoto($product, $file, $membership->party, $this->account($request));
        } catch (RuntimeException $e) {
            return back()->withErrors(['photo' => $e->getMessage()]);
        }

        return back()->with('status', 'Photograph added.');
    }

    public function withdrawPhoto(Request $request, Enterprise $enterprise, Product $product, Media $media): RedirectResponse
    {
        $membership = $this->editing($request, $enterprise);
        $this->belongs($enterprise, $product);

        try {
            $this->catalogue->withdrawPhoto($product, $media, $membership->party);
        } catch (RuntimeException) {
            abort(404);
        }

        return back()->with('status', 'Photograph removed.');
    }

    /** @return array{name: string, unit?: string|null, price_naira?: int|null, description?: string|null, status?: string|null} */
    private function validated(Request $request): array
    {
        /** @var array{name: string, unit?: string|null, price_naira?: int|null, description?: string|null, status?: string|null} */
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'unit' => ['nullable', 'string', 'max:60'],
            'price_naira' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in([Product::STATUS_ACTIVE, Product::STATUS_HIDDEN])],
        ]);
    }

    private function belongs(Enterprise $enterprise, Product $product): void
    {
        abort_unless($product->enterprise_id === $enterprise->id && $product->status !== Product::STATUS_WITHDRAWN, 404);
    }
}
