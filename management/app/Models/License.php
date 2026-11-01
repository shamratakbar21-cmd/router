<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class License extends Model
{
    protected $fillable = [
        'uuid',
        'license_plan_id',
        'license_activation_key_id',
        'customer_label',
        'entitlements',
        'starts_at',
        'expires_at',
        'offline_grace_days',
        'status',
        'signing_key_id',
    ];

    protected function casts(): array
    {
        return [
            'entitlements' => 'array',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'offline_grace_days' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(LicensePlan::class, 'license_plan_id');
    }

    public function activationKey(): BelongsTo
    {
        return $this->belongsTo(LicenseActivationKey::class, 'license_activation_key_id');
    }

    public function activations(): HasMany
    {
        return $this->hasMany(LicenseActivation::class);
    }

    public function isExpired(?\DateTimeInterface $now = null): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->lessThan($now ?? now());
    }

    public function isRevoked(): bool
    {
        return $this->status === 'revoked';
    }
}
