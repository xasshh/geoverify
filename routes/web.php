<?php

declare(strict_types=1);

use App\Domain\Coverage\Actions\CheckSpatialStack;
use App\Http\Controllers\Console\AssignmentController;
use App\Http\Controllers\Console\CoverageController;
use App\Http\Controllers\Field\AssignmentBoardController;
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
});

/*
| The field client. An officer sees their own work and nothing else.
*/
Route::middleware(['auth', 'field'])->prefix('field')->name('field.')->group(function (): void {
    Route::get('/', [AssignmentBoardController::class, 'index'])->name('index');
});
