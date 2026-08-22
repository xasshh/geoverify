<?php

declare(strict_types=1);

use App\Domain\Coverage\Actions\CheckSpatialStack;
use App\Http\Controllers\Console\CoverageController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn (CheckSpatialStack $check) => Inertia::render('Health', $check()))
    ->name('health');

// The design system gallery. Never routed in production: it exists so the
// primitives can be reviewed in every state before any feature screen uses them.
if (app()->isLocal()) {
    Route::get('/design', fn () => Inertia::render('Design'))->name('design');
}

// Supervisor console. Authentication and roles arrive at M3; until then these are
// read-only views over loaded reference data.
Route::prefix('console')->name('console.')->group(function (): void {
    Route::get('coverage/{coverageArea}', [CoverageController::class, 'show'])->name('coverage');
    Route::get('coverage/{coverageArea}/cells.geojson', [CoverageController::class, 'cells'])->name('coverage.cells');
    Route::get('coverage/{coverageArea}/boundary.geojson', [CoverageController::class, 'boundary'])->name('coverage.boundary');
});
