<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseActivation extends Model
{
    protected $fillable = [
        'uuid',
        'license_id',
        'router_node_id',
        'device_id',
        'device_fingerprint',
        'request_nonce',
        'activation_mode',
        'signed_payload',
        'payload_hash',
        'activated_at',
        'last_seen_at',
        'last_status_refresh_at',
        'grace_expires_at',
        'status',
        'revoked_at',
        'revoke_reason',
    ];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_status_refresh_at' => 'datetime',
            'grace_expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function routerNode(): BelongsTo
    {
        return $this->belongsTo(RouterNode::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->revoked_at === null;
    }
}
