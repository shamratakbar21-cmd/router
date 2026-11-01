<?php

namespace App\Models;

use Database\Factories\RouterNodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class RouterNode extends Model
{
    /** @use HasFactory<RouterNodeFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'namespace_name',
        'management_interface',
        'management_cidr',
        'appliance_host',
        'appliance_ssh_port',
        'appliance_ssh_user',
        'appliance_ssh_private_key',
        'appliance_ssh_known_host',
        'device_id',
        'active_license_activation_id',
        'license_checked_at',
        'license_status',
        'status',
        'desired_configuration',
        'desired_secret_configuration',
        'desired_revision_id',
        'applied_revision_id',
        'applied_configuration',
        'applied_secret_configuration',
        'configuration_lock_version',
        'pending_operation_uuid',
        'reconciliation_deadline_at',
        'safe_mode_confirm_by',
        'safe_mode_status',
        'current_revision_id',
    ];

    protected function casts(): array
    {
        return [
            'applied_configuration' => 'array',
            'applied_secret_configuration' => 'encrypted:array',
            'appliance_ssh_known_host' => 'encrypted',
            'appliance_ssh_port' => 'integer',
            'appliance_ssh_private_key' => 'encrypted',
            'active_license_activation_id' => 'integer',
            'license_checked_at' => 'datetime',
            'configuration_lock_version' => 'integer',
            'desired_configuration' => 'array',
            'desired_secret_configuration' => 'encrypted:array',
            'reconciliation_deadline_at' => 'datetime',
            'safe_mode_confirm_by' => 'datetime',
        ];
    }

    public function desiredRevision(): BelongsTo
    {
        return $this->belongsTo(ConfigurationRevision::class, 'desired_revision_id');
    }

    public function appliedRevision(): BelongsTo
    {
        return $this->belongsTo(ConfigurationRevision::class, 'applied_revision_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ConfigurationRevision::class);
    }

    public function latestObservedState(): HasOne
    {
        return $this->hasOne(ObservedState::class)->latestOfMany('recorded_at');
    }

    public function observedStates(): HasMany
    {
        return $this->hasMany(ObservedState::class);
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    public function usesApplianceConnection(): bool
    {
        return filled($this->appliance_host)
            && filled($this->appliance_ssh_user)
            && filled($this->appliance_ssh_private_key)
            && filled($this->appliance_ssh_known_host);
    }

    public function hasStagedConfiguration(): bool
    {
        return $this->desired_revision_id !== null
            && $this->desired_revision_id !== $this->applied_revision_id;
    }
}
