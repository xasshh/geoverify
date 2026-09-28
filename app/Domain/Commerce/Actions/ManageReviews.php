<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\PurchaseStatus;
use App\Domain\Commerce\Models\PurchaseOrder;
use App\Domain\Commerce\Models\Review;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reviews, and the rules that keep them honest.
 *
 * Only a buyer whose order was released can review it, once: a rating is worth
 * something only because every one of them is a completed purchase. A review
 * may not carry a phone number or an email address, because a review is public
 * and somebody's contact details are not the reviewer's to publish. Anyone
 * signed in may report one; an admin hides or restores it with a reason, and
 * nothing is deleted.
 */
final class ManageReviews
{
    public function write(PurchaseOrder $order, PortalAccount $buyer, int $rating, ?string $body): Review
    {
        if ($order->buyer_account_id !== $buyer->id) {
            throw new RuntimeException('That order is not yours.');
        }

        if ($order->status !== PurchaseStatus::Released) {
            throw new RuntimeException('You can review an order once it has been delivered and released.');
        }

        if ($rating < 1 || $rating > 5) {
            throw new RuntimeException('Give between one and five stars.');
        }

        $body = $body === null ? null : trim($body);

        if ($body !== null && $body !== '' && self::carriesContactDetails($body)) {
            throw new RuntimeException('Leave phone numbers and email addresses out of a review. It is public.');
        }

        if (Review::query()->where('purchase_order_id', $order->id)->exists()) {
            throw new RuntimeException('You have already reviewed this order.');
        }

        $review = Review::query()->create([
            'purchase_order_id' => $order->id,
            'enterprise_id' => $order->enterprise_id,
            'buyer_account_id' => $buyer->id,
            'rating' => $rating,
            'body' => $body === '' || $body === null ? null : mb_substr($body, 0, 1000),
            'status' => Review::PUBLISHED,
        ]);

        VerificationEvent::recordForBuyer($order, 'purchase.reviewed', $buyer, ['rating' => $rating]);

        return $review;
    }

    public function report(Review $review, PortalAccount $reporter, string $reason): void
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw new RuntimeException('Say what is wrong with the review, in a sentence.');
        }

        DB::table('review_reports')->insertOrIgnore([
            'review_id' => $review->id,
            'reporter_account_id' => $reporter->id,
            'reason' => mb_substr($reason, 0, 500),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function moderate(Review $review, User $admin, bool $hide, string $note): Review
    {
        if (! $admin->role->administers()) {
            throw new RuntimeException('Only an admin moderates reviews.');
        }

        if (trim($note) === '') {
            throw new RuntimeException('Write down why.');
        }

        return DB::transaction(function () use ($review, $admin, $hide, $note): Review {
            $review->update([
                'status' => $hide ? Review::HIDDEN : Review::PUBLISHED,
                'moderated_by' => $admin->id,
                'moderated_at' => now(),
                'moderation_note' => mb_substr(trim($note), 0, 500),
            ]);

            DB::table('review_reports')->where('review_id', $review->id)->whereNull('resolved_at')->update(['resolved_at' => now()]);

            VerificationEvent::record($review, $hide ? 'review.hidden' : 'review.restored', $admin, ['note' => $note]);

            return $review;
        });
    }

    /** Seven or more digits in a run (allowing spaces and dashes), or anything shaped like an email. */
    public static function carriesContactDetails(string $text): bool
    {
        return preg_match('/(\+?\d[\d\s\-().]{6,}\d)/', $text) === 1
            || preg_match('/[^\s@]+@[^\s@]+\.[^\s@]+/', $text) === 1;
    }
}
