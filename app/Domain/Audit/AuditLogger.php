<?php

namespace App\Domain\Audit;

use App\Domain\Tenancy\TenantContext;
use App\Models\AuditLog;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AuditLogger
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuthFactory $auth,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $metadata = [],
        ?int $organizationId = null,
        ?int $userId = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'organization_id' => $organizationId ?? ($this->tenant->has() ? $this->tenant->id() : null),
            'user_id' => $userId ?? $this->auth->guard()->id(),
            'action' => $action,
            'subject_type' => $subject !== null ? Str::snake(class_basename($subject)) : null,
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata === [] ? null : Redactor::redact($metadata),
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 250, ''),
        ]);
    }
}
