<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LicenseActivationKey extends Model
{
    protected $fillable = [
        'license_plan_id',
        'key_prefix',
        'key_hash',
        'customer_label',
        'max_activations',
        'expires_at',
        'status',
        'created_by',
        'revoked_at',
    ];

    protected $hidden = [
        'key_hash',
    ];

    protected function casts(): array
    {
        return [
            'max_activations' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(LicensePlan::class, 'license_plan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    public function isUsable(): bool
    {
        if ($this->status !== 'available' && $this->status !== 'partially_used') {
            return false;
        }

        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
