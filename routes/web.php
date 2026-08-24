<?php

declare(strict_types=1);

use App\Domain\Coverage\Actions\CheckSpatialStack;
use App\Http\Controllers\Console\AssignmentController;
use App\Http\Controllers\Console\CoverageController;
use App\Http\Controllers\Console\ReviewController;
use App\Http\Controllers\Field\AssignmentBoardController;
use App\Http\Controllers\Field\CaptureController;
use App\Http\Controllers\Field\CaptureScreenController;
use App\Http\Controllers\Field\MapPackController;
use App\Http\Controllers\Field\SyncController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn (CheckSpatialStack $check) => Inertia::render('Health', $check()))
    ->name('health');

// The design system gallery. Never routed in production: it exists so the
// primitives can be reviewed in every state before any feature screen uses them.
if (app()->isLocal()) {
    Route::get('/design', fn () => Inertia::render('Design'))->name('design');
}

/*
| The supervisor console. Assigning ground, and seeing who holds what.
*/
Route::middleware(['auth', 'supervises'])->prefix('console')->name('console.')->group(function (): void {
    Route::get('coverage', [CoverageController::class, 'index'])->name('coverage.index');
    Route::get('coverage/{coverageArea}', [CoverageController::class, 'show'])->name('coverage');
    Route::get('coverage/{coverageArea}/cells.geojson', [CoverageController::class, 'cells'])->name('coverage.cells');
    Route::get('coverage/{coverageArea}/boundary.geojson', [CoverageController::class, 'boundary'])->name('coverage.boundary');

    Route::get('coverage/{coverageArea}/assignments', [AssignmentController::class, 'index'])->name('assignments');
    Route::post('coverage/{coverageArea}/assignments', [AssignmentController::class, 'store'])->name('assignments.store');
    Route::delete('assignments/{assignment}', [AssignmentController::class, 'destroy'])->name('assignments.release');

    // Review. The queue is ordered worst first, so this is where a supervisor
    // starts their morning rather than somewhere they end up.
    Route::get('review', [ReviewController::class, 'index'])->name('review.index');
    Route::get('review/{observation}', [ReviewController::class, 'show'])->name('review.show');
    Route::post('review/{observation}', [ReviewController::class, 'decide'])->name('review.decide');
});

/*
| The field client. An officer sees their own work and nothing else.
*/
Route::middleware(['auth', 'field'])->prefix('field')->name('field.')->group(function (): void {
    Route::get('/', [AssignmentBoardController::class, 'index'])->name('index');
    Route::get('assignments/{assignment}/capture', [CaptureScreenController::class, 'show'])->name('capture');
});

/*
| The field client's capture endpoints. Session authenticated for the online
| flow; the offline client authenticates by device token at M5.
*/
Route::middleware(['auth', 'field'])->prefix('api/field')->name('api.field.')->group(function (): void {
    Route::post('sessions', [CaptureController::class, 'startSession'])->name('sessions.start');
    Route::post('sessions/{session}/fixes', [CaptureController::class, 'appendFixes'])->name('sessions.fixes');
    Route::post('sessions/{session}/end', [CaptureController::class, 'endSession'])->name('sessions.end');

    Route::post('structures', [CaptureController::class, 'storeStructure'])->name('structures.store');
    Route::post('enterprises', [CaptureController::class, 'storeEnterprise'])->name('enterprises.store');
    Route::post('photographs', [CaptureController::class, 'storePhotograph'])->name('photographs.store');
    Route::get('photographs/{media}', [CaptureController::class, 'showPhotograph'])->name('photographs.show');

    Route::get('sectors', [CaptureController::class, 'searchSectors'])->name('sectors');

    // The offline map pack: what is available, and the bytes.
    Route::get('packs', [MapPackController::class, 'index'])->name('packs.index');
    Route::get('packs/{pack}', [MapPackController::class, 'show'])->name('packs.show');

    // Where a handset that has been offline tells the server what happened.
    Route::post('sync', SyncController::class)->name('sync');
});
