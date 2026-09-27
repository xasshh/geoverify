<?php

declare(strict_types=1);

namespace App\Domain\Investment\Actions;

use App\Domain\Investment\Models\DataRoomDocument;
use App\Domain\Investment\Models\DataRoomGrant;
use App\Domain\Investment\Models\InvestorUser;
use App\Domain\Investment\Models\Opportunity;
use App\Domain\Party\Models\Party;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The data room: documents a business shares, and who may read them.
 *
 * Documents live on the private media disk and are served only through a
 * controller that checks a grant on every request. Nothing here is ever given
 * a public URL. Access is per organisation and is the business's decision;
 * revoking closes the room on the next request.
 */
final class ManageDataRoom
{
    public const DISK = 'media';

    public function upload(Opportunity $opportunity, UploadedFile $file, string $title, ?string $description, Party $party, PortalAccount $account): DataRoomDocument
    {
        $path = $file->storeAs(
            'data-rooms/'.$opportunity->id,
            Str::uuid7()->toString().'.'.($file->guessExtension() ?? 'bin'),
            self::DISK,
        );

        if ($path === false) {
            throw new InvalidArgumentException('The document could not be stored.');
        }

        $document = DataRoomDocument::query()->create([
            'opportunity_id' => $opportunity->id,
            'title' => $title,
            'description' => $description,
            'disk' => self::DISK,
            'path' => $path,
            'mime' => (string) $file->getMimeType(),
            'bytes' => (int) $file->getSize(),
            'uploaded_by_party_id' => $party->id,
            'uploaded_by_account_id' => $account->id,
            'status' => DataRoomDocument::STATUS_ACTIVE,
        ]);

        VerificationEvent::recordForParty($opportunity->enterprise, 'data_room.document_added', $party, [
            'opportunity_id' => $opportunity->id,
            'document_id' => $document->id,
        ]);

        return $document;
    }

    public function withdrawDocument(DataRoomDocument $document, Party $party): void
    {
        $document->forceFill(['status' => DataRoomDocument::STATUS_WITHDRAWN])->save();

        VerificationEvent::recordForParty($document->opportunity->enterprise, 'data_room.document_withdrawn', $party, [
            'document_id' => $document->id,
        ]);
    }

    public function request(Opportunity $opportunity, InvestorUser $investor, ?string $message): DataRoomGrant
    {
        $grant = DataRoomGrant::query()->firstOrNew([
            'opportunity_id' => $opportunity->id,
            'investor_organisation_id' => $investor->investor_organisation_id,
        ]);

        // A room already open stays open; a declined or revoked request may be
        // asked again, which puts it back in front of the business.
        if ($grant->exists && $grant->isGranted()) {
            return $grant;
        }

        $grant->fill([
            'status' => DataRoomGrant::STATUS_REQUESTED,
            'requested_by' => $investor->id,
            'message' => $message,
            'decided_by_account_id' => null,
            'decided_at' => null,
        ])->save();

        VerificationEvent::recordForInvestor($opportunity, 'data_room.requested', $investor);

        return $grant;
    }

    public function decide(DataRoomGrant $grant, string $decision, Party $party, PortalAccount $account): void
    {
        if (! in_array($decision, [DataRoomGrant::STATUS_GRANTED, DataRoomGrant::STATUS_DECLINED, DataRoomGrant::STATUS_REVOKED], true)) {
            throw new InvalidArgumentException('A data room decision is granted, declined or revoked.');
        }

        $grant->forceFill([
            'status' => $decision,
            'decided_by_account_id' => $account->id,
            'decided_at' => now(),
        ])->save();

        VerificationEvent::recordForParty($grant, 'data_room.'.$decision, $party, [
            'grant_id' => $grant->id,
            'investor_organisation_id' => $grant->investor_organisation_id,
        ]);
    }
}
