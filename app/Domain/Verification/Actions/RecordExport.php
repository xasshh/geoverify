<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Verification\Exports\ExportScope;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Generator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands a file over, and writes down that it was handed over.
 *
 * Every export appends to verification_events, because a register whose copies
 * left without trace cannot answer the one question an auditor always asks:
 * who has this, and is what they are holding what we sent. The row carries the
 * scope, the row count and the SHA-256 of the exact bytes that went out, so a
 * file produced in evidence months later can be checked against it rather than
 * taken on trust.
 *
 * The hash is computed while streaming rather than by buffering the file, so a
 * mandate sized export still costs one row of memory at a time.
 */
final class RecordExport
{
    public function __invoke(
        ExportScope $scope,
        User $actor,
        string $format,
        string $filename,
        string $contentType,
        Generator $chunks,
    ): StreamedResponse {
        return response()->streamDownload(
            function () use ($scope, $actor, $format, $chunks): void {
                $hash = hash_init('sha256');
                $bytes = 0;
                $rows = 0;

                foreach ($chunks as $chunk) {
                    hash_update($hash, $chunk);
                    $bytes += strlen($chunk);
                    $rows++;

                    echo $chunk;
                }

                // Written after the last byte, so an export that died halfway
                // is not recorded as one that completed. A client holding a
                // truncated file and a log saying it was fine is worse than no
                // log at all.
                VerificationEvent::record(
                    $scope->area,
                    'export.taken',
                    $actor,
                    [
                        ...$scope->toEvidence(),
                        'format' => $format,
                        'rows' => max(0, $rows - 1),
                        'bytes' => $bytes,
                        'sha256' => hash_final($hash),
                    ],
                );
            },
            $filename,
            [
                'Content-Type' => $contentType,
                // Streamed, so the length is not known when the headers go out.
                'X-Accel-Buffering' => 'no',
            ],
        );
    }
}
