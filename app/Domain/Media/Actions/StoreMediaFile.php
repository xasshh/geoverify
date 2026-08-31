<?php

declare(strict_types=1);

namespace App\Domain\Media\Actions;

use App\Domain\Media\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Putting a file on the media disk and recording what it is.
 *
 * Extracted from StorePhotograph when a party needed to upload a document
 * supporting a correction. Everything here is true of both and specific to
 * neither: the size guard, the idempotency, the digest, the write, and the row.
 *
 * What is deliberately not here is authorship and provenance. A photograph
 * carries EXIF, a capture point and the distance from its subject, and it is
 * attributed to the officer who stood there. A document carries none of that
 * and is attributed to a party who asserted something. Those differences are
 * the point of keeping the two callers apart: the moment this class started
 * deciding between them, a certificate would end up with a coordinate on it.
 */
final class StoreMediaFile
{
    /** Above this the upload is refused. The field client compresses first. */
    public const MAX_BYTES = 4 * 1024 * 1024;

    /**
     * @param  array<string, mixed>  $attributes  Authorship and anything the caller knows that this does not.
     */
    public function put(
        UploadedFile $file,
        Model $subject,
        string $kind,
        string $clientUuid,
        array $attributes = [],
        int $maxBytes = self::MAX_BYTES,
    ): Media {
        if ($file->getSize() > $maxBytes) {
            throw new RuntimeException(
                'That file is too large. It should have been compressed before sending.',
            );
        }

        $existing = Media::query()->where('client_uuid', $clientUuid)->first();

        if ($existing instanceof Media) {
            // A retried upload. One file, same answer.
            return $existing;
        }

        $path = $file->getRealPath();
        $sha256 = $path === false ? '' : (hash_file('sha256', $path) ?: '');

        $stored = $file->store($this->directory($subject), 'media');

        if ($stored === false) {
            throw new RuntimeException('The file could not be stored. Try again.');
        }

        return Media::query()->create([
            'mediable_type' => $subject->getMorphClass(),
            'mediable_id' => $subject->getKey(),
            'kind' => $kind,
            'disk' => 'media',
            'disk_path' => $stored,
            'sha256' => $sha256,
            'bytes' => $file->getSize(),
            'client_uuid' => $clientUuid,
            ...$attributes,
        ]);
    }

    /**
     * Where on the disk.
     *
     * Keyed on the subject rather than on the uploader, so everything about one
     * business sits together whoever produced it, which is what somebody
     * answering a subject access request actually needs.
     */
    private function directory(Model $subject): string
    {
        $folder = str(class_basename($subject))->snake()->plural()->toString();

        return "{$folder}/{$subject->getKey()}";
    }
}
