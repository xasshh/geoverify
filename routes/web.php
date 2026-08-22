<?php

declare(strict_types=1);

use App\Domain\Coverage\Actions\CheckSpatialStack;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn (CheckSpatialStack $check) => Inertia::render('Health', $check()))
    ->name('health');
