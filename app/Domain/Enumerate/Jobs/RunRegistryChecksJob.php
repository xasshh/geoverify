<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Jobs;

use App\Domain\Enumerate\Actions\RunRegistryChecks;
use App\Domain\Enumerate\Models\EnumerateRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The lookups, off the request that paid for them: a provider taking twenty
 * seconds to answer should not hold a person's browser for twenty seconds.
 */
final class RunRegistryChecksJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $requestId) {}

    public function handle(RunRegistryChecks $checks): void
    {
        $request = EnumerateRequest::query()->find($this->requestId);

        if ($request !== null) {
            $checks($request);
        }
    }
}
