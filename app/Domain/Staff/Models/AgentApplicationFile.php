<?php

declare(strict_types=1);

namespace App\Domain\Staff\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A document an applicant uploaded: their photograph, an ID, a certificate.
 *
 * Encrypted before it reaches the disk and read back only by an administrator.
 * Never `media`: nothing here is ever a candidate for publication, so it does
 * not sit in the table the directory reads from. Replacing a document marks the
 * old one replaced and keeps it.
 *
 * @property int $id
 * @property int $agent_application_id
 * @property string $kind
 * @property string|null $label
 * @property string $path
 * @property string $mime
 * @property int $size_bytes
 * @property Carbon|null $replaced_at
 * @property Carbon $created_at
 */
final class AgentApplicationFile extends Model
{
    protected $fillable = ['agent_application_id', 'kind', 'label', 'path', 'mime', 'size_bytes', 'replaced_at'];

    protected function casts(): array
    {
        return ['replaced_at' => 'datetime'];
    }

    /** @return BelongsTo<AgentApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(AgentApplication::class, 'agent_application_id');
    }
}
