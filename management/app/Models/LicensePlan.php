<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LicensePlan extends Model
{
    protected $fillable = [
        'code',
        'name',
        'duration_days',
        'entitlements',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'entitlements' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function activationKeys(): HasMany
    {
        return $this->hasMany(LicenseActivationKey::class);
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }
}
