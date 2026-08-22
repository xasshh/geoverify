<?php

declare(strict_types=1);
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against a real PostgreSQL database with PostGIS and H3
| enabled. There is no in-memory substitute: this system's behaviour is defined
| by spatial predicates, so a test that does not exercise them proves nothing.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
