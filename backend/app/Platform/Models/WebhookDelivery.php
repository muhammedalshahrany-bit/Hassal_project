<?php

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_DELIVERED = 'DELIVERED';
    public const STATUS_FAILED = 'FAILED';

    /**
     * Never attempted: the endpoint resolved to a private or internal address,
     * so posting to it would have been a request forgery rather than a delivery.
     */
    public const STATUS_BLOCKED = 'BLOCKED';

    protected $fillable = [
        'webhook_id',
        'event_name',
        'event_id',
        'payload',
        'signature',
        'status',
        'response_status',
        'response_body',
        'attempt',
        'last_error',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response_status' => 'integer',
            'attempt' => 'integer',
            'delivered_at' => 'datetime',
        ];
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
}
