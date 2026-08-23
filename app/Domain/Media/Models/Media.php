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

    protected $table = 'media';

    protected $fillable = [
        'mediable_type', 'mediable_id', 'kind', 'disk', 'disk_path', 'thumb_path',
        'sha256', 'bytes', 'width', 'height', 'captured_at', 'exif',
        'distance_from_subject_m', 'from_device_camera', 'captured_by',
        'field_session_id', 'status', 'client_uuid',
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

    /** Short lived by default: a link that outlives the screen it was made for. */
    public function temporaryUrl(int $minutes = 10): string
    {
        return Storage::disk($this->disk)->temporaryUrl(
            $this->disk_path,
            now()->addMinutes($minutes),
        );
    }
}
