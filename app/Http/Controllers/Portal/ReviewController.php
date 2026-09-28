<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Commerce\Actions\ManageReviews;
use App\Domain\Commerce\Models\PurchaseOrder;
use App\Domain\Commerce\Models\Review;
use App\Http\Controllers\Portal\Concerns\ActsForBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** A buyer reviews a released order; anyone signed in may report a review. */
final class ReviewController
{
    use ActsForBusiness;

    public function __construct(private readonly ManageReviews $reviews) {}

    public function store(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $input = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'body' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->reviews->write($order, $this->account($request), (int) $input['rating'], $input['body'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['review' => $e->getMessage()]);
        }

        return back()->with('status', 'Thank you. Your review is on the business’s page.');
    }

    public function report(Request $request, Review $review): RedirectResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        try {
            $this->reviews->report($review, $this->account($request), $reason);
        } catch (RuntimeException $e) {
            return back()->withErrors(['report' => $e->getMessage()]);
        }

        return back()->with('status', 'Reported. Our team will look at it.');
    }
}
