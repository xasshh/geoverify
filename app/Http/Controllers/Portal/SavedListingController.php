<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Registry\Actions\SearchDirectory;
use App\Domain\Registry\Models\Enterprise;
use App\Http\Controllers\Portal\Concerns\ActsForBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Saved businesses. The list is resolved through the directory's own search,
 * so a business that has since been withheld drops out of it rather than
 * lingering in somebody's bookmarks.
 */
final class SavedListingController
{
    use ActsForBusiness;

    public function index(Request $request, SearchDirectory $directory): Response
    {
        $ids = DB::table('saved_listings')
            ->where('portal_account_id', $this->account($request)->id)
            ->whereNull('removed_at')
            ->orderByDesc('updated_at')
            ->pluck('enterprise_id');

        $listings = Enterprise::query()->whereIn('id', $ids)->get()
            ->map(static fn (Enterprise $e): ?array => $directory->listing($e))
            ->filter()
            ->values()
            ->all();

        return Inertia::render('portal/Saved', ['listings' => $listings]);
    }

    public function toggle(Request $request, Enterprise $enterprise, SearchDirectory $directory): RedirectResponse
    {
        abort_if($directory->listing($enterprise) === null, 404);

        $account = $this->account($request);
        $row = DB::table('saved_listings')->where('portal_account_id', $account->id)->where('enterprise_id', $enterprise->id)->first();

        if ($row === null) {
            DB::table('saved_listings')->insert([
                'portal_account_id' => $account->id,
                'enterprise_id' => $enterprise->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $saved = true;
        } else {
            $saved = $row->removed_at !== null;
            DB::table('saved_listings')->where('id', $row->id)->update(['removed_at' => $saved ? null : now(), 'updated_at' => now()]);
        }

        return back()->with('status', $saved ? 'Saved.' : 'Removed from your saved businesses.');
    }
}
