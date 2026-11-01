<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseTransfer extends Model
{
    protected $fillable = [
        'license_id',
        'from_activation_id',
        'from_device_id',
        'to_device_id',
        'to_activation_id',
        'status',
        'requested_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
