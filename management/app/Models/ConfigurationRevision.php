<?php

namespace App\Models;

use Database\Factories\ConfigurationRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfigurationRevision extends Model
{
    /** @use HasFactory<ConfigurationRevisionFactory> */
    use HasFactory;

    protected $hidden = ['desired_secret_configuration', 'applied_secret_configuration'];

    protected $fillable = [
        'router_node_id',
        'user_id',
        'revision_number',
        'base_lock_version',
        'operation_uuid',
        'confirm_by',
        'confirmed_at',
        'stale_at',
        'desired_configuration',
        'desired_secret_configuration',
        'applied_configuration',
        'applied_secret_configuration',
        'diff_summary',
        'rollback_of_revision_id',
        'apply_status',
        'applied_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
            'applied_configuration' => 'array',
            'applied_secret_configuration' => 'encrypted:array',
            'base_lock_version' => 'integer',
            'confirm_by' => 'datetime',
            'confirmed_at' => 'datetime',
            'desired_configuration' => 'array',
            'desired_secret_configuration' => 'encrypted:array',
            'diff_summary' => 'array',
            'stale_at' => 'datetime',
        ];
    }

    public function routerNode(): BelongsTo
    {
        return $this->belongsTo(RouterNode::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
