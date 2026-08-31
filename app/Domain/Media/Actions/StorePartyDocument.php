<?php

declare(strict_types=1);

namespace App\Domain\Media\Actions;

use App\Domain\Media\Models\Media;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * A document a party uploaded to support something it is asserting.
 *
 * The same disk, the same table and the same signed URL contract as an
 * officer's photograph, through the same StoreMediaFile. What is different is
 * what it is worth: a photograph is the record of somebody standing at a door,
 * and a certificate is a claim made by the person it benefits. The `media` table
 * allows exactly one author for that reason, and this path never sets the
 * officer one.
 *
 * No position is recorded. A document has no capture point, and inventing one
 * would put a coordinate beside a certificate as though somebody had stood
 * somewhere to produce it.
 */
final class StorePartyDocument
{
    /** Smaller than a field photograph: this is a scan or a phone snap of paper. */
    private const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly StoreMediaFile $files) {}

    public function __invoke(
        UploadedFile $file,
        Model $subject,
        Party $party,
        PortalAccount $uploader,
        string $clientUuid,
    ): Media {
        if (! in_array($file->getClientMimeType(), self::accepted(), true)) {
            throw new RuntimeException(
                'Upload a photograph or a PDF. Other formats cannot be read by the people reviewing this.',
            );
        }

        $media = $this->files->put($file, $subject, Media::KIND_DOCUMENT, $clientUuid, [
            'uploaded_by_party_id' => $party->id,
            'uploaded_by_account_id' => $uploader->id,
        ], self::MAX_BYTES);

        if ($media->wasRecentlyCreated) {
            VerificationEvent::record(
                $media,
                'media.uploaded_by_party',
                null,
                [
                    'party_id' => $party->id,
                    'account_id' => $uploader->id,
                    'sha256' => $media->sha256,
                    'bytes' => $media->bytes,
                    'subject' => $subject->getMorphClass().'#'.(string) $subject->getKey(),
                ],
                VerificationEvent::ACTOR_PARTY,
            );
        }

        return $media;
    }

    /** @return list<string> */
    private static function accepted(): array
    {
        return ['image/jpeg', 'image/png', 'image/heic', 'image/webp', 'application/pdf'];
    }
}
