<?php

declare(strict_types=1);

use App\Domain\Coverage\Actions\CheckSpatialStack;
use Inertia\Testing\AssertableInertia;

it('renders the health page through Inertia', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Health')
                ->where('postgis', fn (string $v) => str_starts_with($v, '3.'))
                ->has('h3')
                ->where('cellsOverAbuja', 385)
        );
});

it('reports the spatial stack from the database rather than from config', function () {
    $report = app(CheckSpatialStack::class)();

    expect($report)->toHaveKeys(['postgis', 'h3', 'cellsOverAbuja'])
        ->and($report['postgis'])->not->toBe('not installed')
        ->and($report['h3'])->not->toBe('not installed')
        ->and($report['cellsOverAbuja'])->toBe(385);
});
