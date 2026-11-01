<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogWriter
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function write(
        string $action,
        string $summary,
        Model $auditable,
        ?User $user = null,
        string $status = 'success',
        ?array $metadata = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $user?->id,
            'auditable_type' => $auditable::class,
            'auditable_id' => $auditable->getKey(),
            'action' => $action,
            'status' => $status,
            'request_id' => request()?->header('X-Request-Id'),
            'summary' => $summary,
            'metadata' => $metadata,
        ]);
    }
}
