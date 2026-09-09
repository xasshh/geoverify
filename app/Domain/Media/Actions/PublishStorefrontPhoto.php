<?php

declare(strict_types=1);

namespace App\Domain\Media\Actions;

use App\Domain\Media\Models\Media;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * A photograph a business takes of itself, for the public directory.
 *
 * Deliberately not StorePartyDocument, though both are a party uploading a
 * file. A document supports an assertion and is read by a reviewer; this is
 * shown to strangers. Different audience, different rules: no PDFs, a larger
 * ceiling because a shopfront is not a scan of paper, and a kind of its own so
 * the directory's query can name what it publishes rather than excluding what
 * it must not.
 *
 * That last point is the whole reason for a separate kind. Officer photographs
 * live in the same table, by an earlier decision this does not reopen: one
 * storage path, one signed URL contract, one place to look when somebody asks
 * what a decision rested on. What keeps them apart is the database's
 * media_one_author constraint plus a SELECT that asks for storefront photos
 * with a party author, never a SELECT that asks for everything and then drops
 * the evidence. A filter that excludes is one refactor from publishing an
 * interior shot with somebody's family in it.
 */
final class PublishStorefrontPhoto
{
    /** A shopfront, not a document scan, and it will be shown at width. */
    private const MAX_BYTES = 12 * 1024 * 1024;

    private const ACCEPTED = ['image/jpeg', 'image/png', 'image/heic', 'image/webp'];

    /** Enough to show a business; past this it is a gallery nobody curates. */
    public const MAX_PER_BUSINESS = 6;

    public function __construct(private readonly StoreMediaFile $files) {}

    public function __invoke(
        UploadedFile $file,
        Enterprise $enterprise,
        Party $party,
        PortalAccount $uploader,
        string $clientUuid,
    ): Media {
        if (! in_array($file->getClientMimeType(), self::ACCEPTED, true)) {
            throw new RuntimeException(
                'Upload a photograph. PDFs and other documents go with a correction instead.',
            );
        }

        if (self::countFor($enterprise) >= self::MAX_PER_BUSINESS) {
            throw new RuntimeException(sprintf(
                'You can show %d photographs. Take one down before adding another.',
                self::MAX_PER_BUSINESS,
            ));
        }

        $media = $this->files->put($file, $enterprise, Media::KIND_STOREFRONT, $clientUuid, [
            'uploaded_by_party_id' => $party->id,
            'uploaded_by_account_id' => $uploader->id,
        ], self::MAX_BYTES);

        if ($media->wasRecentlyCreated) {
            VerificationEvent::record(
                $enterprise,
                'media.storefront_published',
                null,
                [
                    'party_id' => $party->id,
                    'account_id' => $uploader->id,
                    'sha256' => $media->sha256,
                ],
                VerificationEvent::ACTOR_PARTY,
            );
        }

        return $media;
    }

    /**
     * Taking one down.
     *
     * Withdrawn, not deleted, like everything else here. The file stops being
     * published the moment this runs, which is what the party asked for, and
     * the row stays so a question about what this listing showed last March
     * still has an answer.
     */
    public function withdraw(Media $media, Party $party, PortalAccount $actor): void
    {
        $media->update(['status' => Media::STATUS_WITHDRAWN]);

        VerificationEvent::record(
            $media,
            'media.storefront_withdrawn',
            null,
            ['party_id' => $party->id, 'account_id' => $actor->id],
            VerificationEvent::ACTOR_PARTY,
        );
    }

    public static function countFor(Enterprise $enterprise): int
    {
        return Media::query()
            ->where('mediable_type', $enterprise->getMorphClass())
            ->where('mediable_id', $enterprise->id)
            ->where('kind', Media::KIND_STOREFRONT)
            ->whereNotNull('uploaded_by_party_id')
            ->where('status', Media::STATUS_STORED)
            ->count();
    }
}
