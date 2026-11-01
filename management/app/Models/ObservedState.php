<?php

namespace App\Models;

use Database\Factories\ObservedStateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObservedState extends Model
{
    /** @use HasFactory<ObservedStateFactory> */
    use HasFactory;

    protected $fillable = [
        'router_node_id',
        'configuration_revision_id',
        'source',
        'summary',
        'state',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'state' => 'array',
            'summary' => 'array',
        ];
    }

    public function routerNode(): BelongsTo
    {
        return $this->belongsTo(RouterNode::class);
    }

    public function configurationRevision(): BelongsTo
    {
        return $this->belongsTo(ConfigurationRevision::class);
    }
}
