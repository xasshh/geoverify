<?php

declare(strict_types=1);

namespace App\Domain\Media\Models;

use App\Domain\Field\Models\FieldSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * A photograph, on a private disk.
 *
 * Never served directly. A signed temporary URL is the only way a photograph
 * leaves this system, so a leaked path is not a leaked photograph.
 *
 * @property int $id
 * @property string $mediable_type
 * @property int $mediable_id
 * @property string $kind
 * @property string $disk
 * @property string $disk_path
 * @property string|null $thumb_path
 * @property string $sha256
 * @property int $bytes
 * @property int|null $width
 * @property int|null $height
 * @property Carbon|null $captured_at
 * @property array<string, mixed>|null $exif
 * @property float|null $distance_from_subject_m
 * @property bool|null $from_device_camera
 * @property int $captured_by
 * @property int|null $field_session_id
 * @property string $status
 * @property string $client_uuid
 */
final class Media extends Model
{
    public const KIND_FACADE = 'facade';

    public const KIND_SIGNAGE = 'signage';

    public const KIND_INTERIOR = 'interior';

    public const KIND_DOCUMENT = 'document';

    public const KIND_STREET_CONTEXT = 'street_context';

    /**
     * A photograph a business took of itself, for the public directory.
     *
     * The only kind in this table that is ever shown to a stranger, and the
     * only one a party authors. Named rather than inferred so the directory
     * asks for storefront photographs by name instead of asking for everything
     * and excluding the evidence.
     */
    public const KIND_STOREFRONT = 'storefront';

    /**
     * A photograph of something a business sells, taken by the business.
     * Published with its product and asked for by name, like storefront.
     */
    public const KIND_PRODUCT = 'product';

    /** Held and published. */
    public const STATUS_STORED = 'stored';

    /**
     * Taken down by the party that put it up.
     *
     * Not deleted: the file stops being published, and the row stays so a
     * question about what this listing showed last March still has an answer.
     */
    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $table = 'media';

    protected $fillable = [
        'mediable_type', 'mediable_id', 'kind', 'disk', 'disk_path', 'thumb_path',
        'sha256', 'bytes', 'width', 'height', 'captured_at', 'exif',
        'distance_from_subject_m', 'from_device_camera', 'captured_by',
        'field_session_id', 'status', 'client_uuid',
        'uploaded_by_party_id', 'uploaded_by_account_id',
    ];

    protected function casts(): array
    {
        return [
            'exif' => 'array',
            'captured_at' => 'datetime',
            'bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'distance_from_subject_m' => 'float',
            'from_device_camera' => 'boolean',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    /** @return BelongsTo<FieldSession, $this> */
    public function fieldSession(): BelongsTo
    {
        return $this->belongsTo(FieldSession::class);
    }

    /**
     * Short lived by default: a link that outlives the screen it was made for.
     *
     * Object storage signs its own URL and serves the bytes itself. The local
     * driver offers no such thing and used to throw, so on a machine without S3
     * every path that asked for one failed: an officer could photograph a shop,
     * sync it, and never see it again, and a supervisor could not see it at all.
     *
     * The application stands in for the missing capability with a signed route,
     * which is the contract docs/setup.md already claims: the same short lived
     * signed link on both, so no calling code differs between a laptop and
     * production.
     *
     * Signed relative to the path, not the host. An absolute signature covers
     * APP_URL, which is the name the outside world calls this application and
     * not necessarily the one the request arrived on: reached over a tunnel, or
     * by a laptop's LAN address, every photograph would 403 against a signature
     * computed for somewhere else.
     */
    public function temporaryUrl(int $minutes = 10): string
    {
        return self::signedUrl($this->disk, $this->disk_path, $minutes);
    }

    /**
     * The same link for a path that is not loaded as a model.
     *
     * The review screen assembles its photographs in SQL, so it holds a disk
     * and a path rather than a Media instance. Sharing this keeps one answer to
     * "how is a media file addressed" instead of two that can drift.
     */
    public static function signedUrl(string $disk, string $path, int $minutes = 10): string
    {
        $expiry = now()->addMinutes($minutes);

        // The configured driver, not providesTemporaryUrls(). That reports true
        // for the local adapter, which declares getTemporaryUrl purely in order
        // to throw from it.
        if (config("filesystems.disks.{$disk}.driver") !== 'local') {
            return Storage::disk($disk)->temporaryUrl($path, $expiry);
        }

        return URL::temporarySignedRoute(
            'media.file',
            $expiry,
            ['path' => $path],
            absolute: false,
        );
    }
}
