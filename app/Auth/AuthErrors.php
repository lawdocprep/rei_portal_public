<?php
namespace App\Auth;

final class AuthErrors {
    public const AUTH_REQUIRED = 'AUTH_REQUIRED';
    public const AUTH_REVOKED = 'AUTH_REVOKED';
    public const TENANT_SCOPE_VIOLATION = 'TENANT_SCOPE_VIOLATION';
    public const SERVICE_CONTEXT_FORBIDDEN = 'SERVICE_CONTEXT_FORBIDDEN';
    public const SCOPE_FORBIDDEN = 'SCOPE_FORBIDDEN';
    public const ROLE_FORBIDDEN = 'ROLE_FORBIDDEN';
    public const STATE_INVALID = 'STATE_INVALID';
    public const IDEMPOTENCY_CONFLICT = 'IDEMPOTENCY_CONFLICT';
}
