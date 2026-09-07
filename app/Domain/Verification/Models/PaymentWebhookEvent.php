<?php

declare(strict_types=1);

namespace App\Domain\Verification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What the payment provider told us, recorded before it was believed.
 *
 * @property int $id
 * @property string $provider
 * @property string $provider_event_id
 * @property string $event_type
 * @property bool $signature_verified
 * @property string|null $payment_reference
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 */
final class PaymentWebhookEvent extends Model
{
    protected $fillable = [
        'provider', 'provider_event_id', 'event_type', 'signature_verified',
        'payment_reference', 'payload', 'processed_at', 'processing_note',
    ];

    protected function casts(): array
    {
        return [
            'signature_verified' => 'boolean',
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
