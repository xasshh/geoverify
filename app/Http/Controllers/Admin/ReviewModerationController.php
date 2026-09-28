<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Commerce\Actions\ManageReviews;
use App\Domain\Commerce\Models\Review;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** Reported reviews first, then the latest, for an admin to hide or restore. */
final class ReviewModerationController
{
    public function index(): Response
    {
        $rows = DB::select(<<<'SQL'
            SELECT r.id, r.rating, r.body, r.status, r.created_at, r.moderation_note, e.trading_name,
                   (SELECT count(*) FROM review_reports rr WHERE rr.review_id = r.id AND rr.resolved_at IS NULL) AS open_reports,
                   (SELECT string_agg(rr.reason, ' | ') FROM review_reports rr WHERE rr.review_id = r.id AND rr.resolved_at IS NULL) AS reasons
              FROM reviews r JOIN enterprises e ON e.id = r.enterprise_id
             ORDER BY open_reports DESC, r.created_at DESC
             LIMIT 100
        SQL);

        return Inertia::render('admin/Reviews', [
            'reviews' => array_map(static fn (object $r): array => [
                'id' => (int) $r->id,
                'business' => (string) $r->trading_name,
                'rating' => (int) $r->rating,
                'body' => $r->body,
                'status' => (string) $r->status,
                'openReports' => (int) $r->open_reports,
                'reasons' => $r->reasons,
                'note' => $r->moderation_note,
                'on' => (string) $r->created_at,
            ], $rows),
        ]);
    }

    public function moderate(Request $request, Review $review, ManageReviews $reviews): RedirectResponse
    {
        $input = $request->validate(['hide' => ['required', 'boolean'], 'note' => ['required', 'string', 'max:500']]);
        $admin = $request->user();
        abort_unless($admin instanceof User, 403);

        try {
            $reviews->moderate($review, $admin, (bool) $input['hide'], $input['note']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['note' => $e->getMessage()]);
        }

        return back()->with('status', (bool) $input['hide'] ? 'Review hidden.' : 'Review restored.');
    }
}
