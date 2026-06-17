<?php
declare(strict_types=1);

namespace App\Auth;

use App\Auth\Exceptions\ExpiredException;
use PDO;
use App\Services\Guard\CaseMutationGuard;
use RuntimeException;
use Throwable;

final class AuthMiddleware
{
    public function __construct(
        private PDO $db,
        private TokenVerifier $tokenVerifier,
        private TenantIsolation $tenantIsolation,
        private ScopeGuard $scopeGuard,
        private RoleResolver $roleResolver,
        private CapabilityResolver $capabilityResolver,
        private IdempotencyGuard $idempotency,
        private CaseMutationGuard $caseMutationGuard,
        private ?AuditEmitter $audit = null, // OPTIONAL (external evidence safe)
    ) {}

    /**
     * Canonical policy table.
     * Keys MUST match Router canonical_endpoint strings exactly.
     */
    private const POLICIES = [

        /* ============================================================
         * Public / Identity
         * ============================================================ */
        'GET /health'      => ['public' => true],
        'POST /auth/login' => ['public' => true],

        'GET /session' => [
            'required_scopes' => ['session:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'GET /me' => [
            'required_scopes' => ['session:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'GET /me/profile' => [
            'required_scopes' => ['profile:read'],
            'service_context_allowed' => ['human'],
        ],

        'PATCH /me/profile' => [
            'required_scopes' => ['profile:write'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
        ],

        'POST /me/profile/bootstrap' => [
            'required_scopes' => ['profile:write'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'idempotent' => true,
        ],

        'GET /me/roles' => [
            'required_scopes' => ['session:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        /* ============================================================
        * Case Ingress (Intake / Submissions)
        * ============================================================ */

        'POST /cases/{id}/ingress' => [
            'required_scopes' => ['case:ingress:submit'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
        ],

        'GET /cases/{id}/ingress' => [
            'required_scopes' => ['cases:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'POST /cases/{id}/ingress/{submission_id}/accept' => [
            'required_scopes' => ['case:ingress:review'],
            'required_roles_any' => ['system_admin', 'tenant_admin', 'legal_admin', 'internal_staff'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'audit' => true,
        ],

        'POST /cases/{id}/ingress/{submission_id}/reject' => [
            'required_scopes' => ['case:ingress:review'],
            'required_roles_any' => ['system_admin', 'tenant_admin', 'legal_admin', 'internal_staff'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'audit' => true,
        ],

        /* ============================================================
        * Cases
        * ============================================================ */

        'POST /cases' => [
            'required_scopes' => ['cases:write'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'idempotent' => true,
        ],

        'GET /cases/{id}' => [
            'required_scopes' => ['cases:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'PATCH /cases/{id}' => [
            'required_scopes' => ['cases:write'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'idempotent' => true,
        ],

        'GET /cases/{id}/timeline' => [
            'required_scopes' => ['cases:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        /* ============================================================
        * Case Legal Action Requests (LAR)
        * ============================================================ */

        'POST /cases/{id}/action-requests' => [
            'required_scopes' => ['cases:write'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'audit' => true,
        ],

        'GET /cases/{id}/action-requests' => [
            'required_scopes' => ['cases:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'POST /action-requests/{request_id}/execute' => [
            'required_scopes' => ['cases:write'],
            'required_roles_any' => [
                'system_admin',
                'tenant_admin',
                'legal_admin',
                'attorney',
                'paralegal',
                'pro_se',
            ],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'audit' => true,
            'idempotent' => true,
        ],
                
        /* ============================================================
        * Phase 17 – Legal Action Requests (ADMIN)
        * ============================================================ */

        'GET /admin/action-requests' => [
            'required_scopes' => ['cases:read'],
            'required_roles_any' => [
                'system_admin',
                'tenant_admin',
                'legal_admin',
                'attorney',
                'paralegal',
            ],
            'service_context_allowed' => ['human'],
        ],

        /* ============================================================
        * Legal Action Requests – Review / Execution
        * ============================================================ */

        'PATCH /action-requests/{request_id}' => [
            'required_scopes' => ['cases:write'],
            'required_roles_any' => [
                'system_admin',
                'tenant_admin',
                'legal_admin',
                'attorney',
                'paralegal',
                'pro_se',   // self-represented can execute their own
            ],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'audit' => true,
        ],


        /* ============================================================
        * Case Documents
        * ============================================================ */

        'GET /cases/{id}/documents' => [
            'required_scopes' => ['document:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'POST /cases/{id}/documents' => [
            'required_scopes' => ['document:write'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
        ],

        /* ============================================================
        * Case Document Versions & Lifecycle
        * ============================================================ */

        'POST /case-documents/{id}/versions' => [
            'required_scopes' => ['document:write'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'idempotent' => true,
        ],

        'GET /case-documents/{id}/versions' => [
            'required_scopes' => ['document:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'GET /case-documents/{id}/current-version' => [
            'required_scopes' => ['document:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'POST /case-documents/{id}/finalize' => [
            'required_scopes' => ['document:finalize'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'idempotent' => true,
            'audit' => true,
        ],

        'POST /case-documents/{id}/storage-bind' => [
            'required_scopes' => ['document:write'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'idempotent' => true,
        ],

        'GET /case-documents/{id}/storage' => [
            'required_scopes' => ['document:read'],
            'service_context_allowed' => ['human', 'service'],
        ],


        'POST /case-documents/{id}/verify-integrity' => [
            'required_scopes' => ['document:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'GET /case-documents/{id}/audit' => [
            'required_scopes' => ['evidence:audit:read'],
            'required_roles_any' => ['system_admin', 'tenant_admin', 'internal_staff'],
            'service_context_allowed' => ['human', 'service'],
        ],

        /* ============================================================
        * Storage (Case Document Binaries)
        * ============================================================ */

        'POST /storage/upload' => [
            'required_scopes' => ['document:write'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
        ],

        'GET /storage/resolve' => [
            'required_scopes' => ['document:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        /* ============================================================
         * Phase 11 – External Evidence (PUBLIC + TOKEN)
         * ============================================================ */
        'GET /external/exports/{export_id}/manifest' => [
            'public' => true,
            'evidence_access' => true,
        ],

        'GET /external/exports/{export_id}/verify' => [
            'public' => true,
            'evidence_access' => true,
        ],

        /* ============================================================
         * Phase 12–15 – Evidence Governance (ADMIN)
         * ============================================================ */
        'GET /admin/evidence-access-grants' => [
            'required_scopes' => ['evidence:audit:read'],
            'required_roles_any' => ['system_admin', 'tenant_admin', 'internal_staff'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'GET /admin/evidence-access-audit' => [
            'required_scopes' => ['evidence:audit:read'],
            'required_roles_any' => ['system_admin', 'tenant_admin', 'internal_staff'],
            'service_context_allowed' => ['human', 'service'],
        ],

        // Phase 16.1 – single audit event deep link
        'GET /admin/evidence-access-audit/{event_id}' => [
            'required_scopes' => ['evidence:audit:read'],
            'required_roles_any' => ['system_admin', 'tenant_admin', 'internal_staff'],
            'service_context_allowed' => ['human', 'service'],
        ],

        // Explicit CSV endpoint to prevent bypass
        'GET /admin/evidence-access-audit.csv' => [
            'required_scopes' => ['evidence:audit:read'],
            'required_roles_any' => ['system_admin', 'tenant_admin'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'GET /admin/exports/governance-summary' => [
            'required_scopes' => ['evidence:audit:read'],
            'required_roles_any' => ['system_admin', 'tenant_admin', 'internal_staff'],
            'service_context_allowed' => ['human', 'service'],
        ],

        /* ============================================================
        * Phase 16.x – Timeline Governance (ADMIN)
        * ============================================================ */

        'GET /admin/timeline-policy' => [
            'required_scopes' => ['timeline:read'],
            'required_roles_any' => ['system_admin', 'tenant_admin'],
            'service_context_allowed' => ['human'],
        ],

        'PUT /admin/timeline-policy' => [
            'required_scopes' => ['timeline:read'],
            'required_roles_any' => ['system_admin', 'tenant_admin'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'audit' => true,
        ],


        /* ============================================================
        * Properties (Property-first domain)
        * ============================================================ */

        'GET /properties' => [
            'required_scopes' => ['cases:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'POST /properties' => [
            'required_scopes' => ['cases:write'],
            'service_context_allowed' => ['human', 'service'],
            'mutates_state' => true,
        ],

        'GET /properties/{id}' => [
            'required_scopes' => ['cases:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        // Images

        'POST /documents/property-images' => [
            'required_scopes' => ['document:write'],
            'entity_type' => 'property',
        ],

        'GET /documents/{id}/binary' => [
            'required_scopes' => ['document:read'],
        ],
        
        'GET /properties/{id}/images' => [
            'required_scopes' => ['document:read'],
        ],

        'DELETE /property-images/{id}' => [
            'required_scopes' => ['document:write'],
        ],

        'GET /property-images/{id}/binary' => [
            'required_scopes' => ['document:read'],
        ],

        'GET /property-images/{id}/thumbnail' => [
            'required_scopes' => ['document:read'],
        ],

        'GET /properties/{id}/ownership/validate' => [
            'required_scopes' => ['properties:read'],
            'service_context_allowed' => ['human'],
        ],

        'POST /properties/{id}/ownership/adjudicate' => [
            'required_scopes' => ['properties:write'],
            'required_roles'  => ['system_admin', 'legal_admin'],
            'service_context_allowed' => ['human'],
            'audit' => true,
        ],

        'GET /properties/{id}/relationships' => [
            'required_scopes' => ['properties:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'POST /properties/{id}/relationships' => [
            'required_scopes' => ['properties:write'],
            'roles_any' => ['system_admin','tenant_admin','legal_admin'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'audit' => true,
        ],

        'PATCH /properties/{id}/relationships/{relationship_id}' => [
            'required_scopes' => ['properties:write'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'audit' => true,
        ],

   
        'PATCH /properties/{id}' => [
            'required_scopes' => ['reference:write'],
            'service_context_allowed' => ['human'],
        ],

        'GET /properties/{id}/cases' => [
            'required_scopes' => ['cases:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'POST /properties/{id}/cases' => [
            'required_scopes' => ['cases:write'],
            'auth' => true,
            'tenant_scoped' => true,
            'mutates_state' => true,
            'audit' => true,
            'service_context_allowed' => ['human'],
        ],


        'GET /properties/{id}/documents' => [
            'required_scopes' => ['document:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        /* ============================================================
        * Service of Process (Evidentiary Events)
        * ============================================================ */

        'POST /cases/{id}/process-service-events' => [
            'required_scopes' => ['cases:write'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'audit' => true,
        ],

        'GET /cases/{id}/process-service-events' => [
            'required_scopes' => ['cases:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'GET /process-service-events/{event_id}' => [
            'required_scopes' => ['cases:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'POST /process-service-events/{event_id}/evidence' => [
            'required_scopes' => ['document:write'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'audit' => true,
        ],

        /* ============================================================
        * Phase 14.2 – Procedural Timeline & Deadlines
        * ============================================================ */

        'POST /procedural-deadlines' => [
            'required_scopes' => ['deadline:edit'],
            'required_roles_any' => ['system_admin', 'tenant_admin', 'legal_admin', 'attorney', 'paralegal'],
            'service_context_allowed' => ['human', 'service'],
            'mutates_state' => true,
            'audit' => true,
        ],

        'POST /procedural-deadlines/ingest' => [
            'required_scopes' => ['deadline:ingest'],
            'required_roles_any' => ['system_admin', 'tenant_admin', 'legal_admin'],
            'service_context_allowed' => ['human', 'service'],
            'mutates_state' => true,
            'audit' => true,
        ],

        'PATCH /procedural-deadlines/{id}' => [
            'required_scopes' => ['deadline:edit'],
            'required_roles_any' => ['system_admin', 'tenant_admin', 'legal_admin', 'attorney', 'paralegal'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'audit' => true,
        ],

        'POST /procedural-deadlines/{id}/satisfy' => [
            'required_scopes' => ['deadline:satisfy'],
            'required_roles_any' => ['system_admin', 'tenant_admin', 'legal_admin', 'attorney', 'paralegal'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'audit' => true,
        ],

        'GET /cases/{id}/procedural-state' => [
            'required_scopes' => ['cases:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'GET /admin/timeline-policy' => [
            'required_scopes' => ['timeline:policy:read'],
            'required_roles_any' => ['system_admin', 'tenant_admin'],
            'service_context_allowed' => ['human'],
            'mutates_state' => false,
            'audit' => false,
        ],

        'PUT /admin/timeline-policy' => [
            'required_scopes' => ['timeline:policy:write'],
            'required_roles_any' => ['system_admin', 'tenant_admin'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'audit' => true,
        ],

        'GET /cases/{id}/eligible-actions' => [
        'required_scopes' => ['cases:read'],
        'service_context_allowed' => ['human', 'service'],
        ],

        /* ============================================================
        * Phase 14.4 – Per-Occupant Action Targets
        * ============================================================ */

        'POST /cases/{id}/action-targets' => [
            'required_scopes' => ['cases:write'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
        ],

        'GET /cases/{id}/action-targets' => [
            'required_scopes' => ['cases:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        /* ============================================================
        * Phase 14.4 – Deliverable 4.2 Service Tasks
        * ============================================================ */

        'GET /cases/{id}/service-tasks' => [
            'required_scopes' => ['cases:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'PATCH /service-tasks/{id}' => [
            'required_scopes' => ['cases:write'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'audit' => true,
            'idempotent' => true,
        ],

        'POST /cases/{id}/prerequisites/{action_type}/satisfy' => [
            'auth' => true,
            'mutates_state' => true,
            'tenant_scoped' => true,
            'case_scoped' => true,
            'roles_any' => [
                'system_admin',
                'tenant_admin',
                'legal_admin',
                'attorney',
                'paralegal',
                'pro_se',
            ],
            'capabilities_any' => [
                'procedure:attest_prerequisite',
            ],
        ],

        'POST /cases/{id}/action-requests' => [
            'required_scopes' => ['cases:write'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'audit' => true,

            'auth' => true,
            'tenant_scoped' => true,
            'case_scoped' => true,
            'roles_any' => [
                'system_admin',
                'tenant_admin',
                'legal_admin',
                'attorney',
                'paralegal',
                'pro_se',
                'client',              // fully represented client
            ],
        ],

        'GET /cases/{id}/action-requests' => [
            'required_scopes' => ['cases:read'],
            'service_context_allowed' => ['human','service'],

            'auth' => true,
            'tenant_scoped' => true,
            'case_scoped' => true,
            'roles_any' => [
                'system_admin',
                'tenant_admin',
                'legal_admin',
                'attorney',
                'paralegal',
                'pro_se',
                'client',
            ],
        ],

        'PATCH /action-requests/{request_id}' => [
            'required_scopes' => ['cases:write'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'audit' => true,

            'auth' => true,
            'tenant_scoped' => true,
            'case_scoped' => true,
            'roles_any' => [
                'system_admin',
                'tenant_admin',
                'legal_admin',
                'attorney',
                'paralegal',
            ],
        ],

        'POST /action-requests/{request_id}/execute' => [
            'required_scopes' => ['cases:write'],
            'service_context_allowed' => ['human','service'],
            'mutates_state' => true,
            'audit' => true,

            'auth' => true,
            'tenant_scoped' => true,
            'case_scoped' => true,
            'roles_any' => [
                'system_admin',
                'tenant_admin',
                'legal_admin',
                'attorney',
                'paralegal',
            ],
        ],

        'POST /cases/{id}/action-requests/{request_id}/deliveries' => [
            'required_scopes' => ['cases:write'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'audit' => true,

            'auth' => true,
            'tenant_scoped' => true,
            'case_scoped' => true,
            'roles_any' => [
                'system_admin',
                'tenant_admin',
                'legal_admin',
                'attorney',
                'paralegal',
                'process_server',
            ],
        ],

        /* ============================================================
        * Case Parties (Phase 14.5)
        * ============================================================ */

        'GET /cases/{id}/parties' => [
            'required_scopes' => ['cases:read'],
            'auth' => true,
            'tenant_scoped' => true,
            'case_scoped' => true,
            'service_context_allowed' => ['human', 'service'],
        ],

        'POST /cases/{id}/parties' => [
            'required_scopes' => ['cases:write'],
            'auth' => true,
            'tenant_scoped' => true,
            'case_scoped' => true,
            'mutates_state' => true,
            'audit' => true,
            'service_context_allowed' => ['human'],
        ],

        'DELETE /cases/{id}/parties/{actor_id}/{party_role}' => [
            'required_scopes' => ['cases:write'],
            'auth' => true,
            'tenant_scoped' => true,
            'case_scoped' => true,
            'mutates_state' => true,
            'audit' => true,
            'service_context_allowed' => ['human'],
        ],

        'POST /service-tasks/{id}/certified-mail/delivered' => [
            'auth' => true,
            'tenant_scoped' => true,
            'roles_any' => [
                'system_admin',
                'tenant_admin',
                'legal_admin',
                'attorney',
                'paralegal',
            ],
            'required_scopes' => [
                'cases:write',
            ],
            'mutates_state' => true,
            'audit' => true,
        ],

        'POST /admin/cases/{id}/replay-events' => [
            'required_scopes' => ['cases:write'],
            'required_roles_any' => ['system_admin'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'audit' => true,
            'idempotent' => true, // safe to repeat; engine is idempotent
        ],


        // legal_actions

        'POST /cases/{id}/actions/{action_type}/initiate' => [
            'auth' => true,
            'tenant_scoped' => true,
            'case_scoped' => true,
            'required_scopes' => ['cases:write'],
            'service_context_allowed' => ['human', 'service'],
            'roles_any' => [
                'system_admin',
                'tenant_admin',
                'legal_admin',
                'attorney',
                'paralegal',
                'pro_se',
            ],
            'mutates_state' => true,
            'audit' => true,
        ],

        'GET /cases/{id}/legal-actions' => [
            'auth' => true,
            'tenant_scoped' => true,
            'case_scoped' => true,
            'required_scopes' => ['cases:read'],
            'service_context_allowed' => ['human', 'service'],
            'roles_any' => [
                'system_admin',
                'tenant_admin',
                'legal_admin',
                'attorney',
                'paralegal',
                'pro_se',
                'readonly',
            ],
            'mutates_state' => false,
            'audit' => false,
        ],

        'POST /cases/{id}/legal-actions/{legal_action_id}/court' => [
            'auth' => true,
            'tenant_scoped' => true,
            'case_scoped' => true,
            'required_scopes' => ['cases:write'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'audit' => true,
        ],

        'GET /cases/{id}/legal-actions/{legal_action_id}/filing-readiness' => [
            'required_scopes' => ['cases:read'],
            'service_context_allowed' => ['human', 'service'],

            'auth' => true,
            'tenant_scoped' => true,
            'case_scoped' => true,
            'roles_any' => [
                'system_admin',
                'tenant_admin',
                'legal_admin',
                'attorney',
                'paralegal',
                'pro_se',
                'client',
            ],
        ],

        'POST /cases/{id}/defendants' => [
            'required_scopes' => ['cases:write'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'audit' => true,
        ],

        'GET /cases/{id}/legal-actions/{legal_action_id}/filing-intent' => [
            'auth' => true,
            'roles' => null, // read-only; same as other GET case endpoints
        ],

        'GET /cases/{id}/legal-actions/{legal_action_id}/filing-artifacts' => [
            'auth' => true,
            'roles' => null, // read-only, same as filing-intent
        ],

        'POST /cases/{id}/legal-actions/{legal_action_id}/prepare-for-filing' => [
            'required_scopes' => ['cases:write'],
            'service_context_allowed' => ['human', 'service'],

            'auth' => true,
            'mutates_state' => true,
            'tenant_scoped' => true,
            'case_scoped' => true,

            'roles_any' => [
                'system_admin',
                'tenant_admin',
                'legal_admin',
                'attorney',
                'paralegal',
                'pro_se',
            ],
        ],

        'GET /cases/{id}/legal-actions/{legal_action_id}/filing-bundle' => [
            'required_scopes' => ['cases:read'],
            'service_context_allowed' => ['human', 'service'],

            'auth' => true,
            'tenant_scoped' => true,
            'case_scoped' => true,

            'roles_any' => [
                'system_admin',
                'tenant_admin',
                'legal_admin',
                'attorney',
                'paralegal',
                'pro_se',
                'client',
            ],
        ],

        'POST /cases/{id}/legal-actions/{legal_action_id}/commit-filing' => [
            'required_scopes' => ['cases:write'],
            'service_context_allowed' => false,
            'mutates_state' => true,
            'idempotent' => true,
            'ignore_case_freeze' => true, // ✅ allow idempotent commit call even when already frozen
        ],

        'POST /cases/{id}/legal-actions/{legal_action_id}/generate-filing-documents' => [
            'required_scopes' => ['cases:write'],
            'service_context_allowed' => ['human', 'service'],

            'auth' => true,
            'mutates_state' => true,
            'tenant_scoped' => true,
            'case_scoped' => true,

            'roles_any' => [
                'system_admin',
                'tenant_admin',
                'legal_admin',
                'attorney',
                'paralegal',
                'pro_se',
            ],
        ],

        'GET /cases/{id}/legal-actions/{legal_action_id}/filing-documents' => [
            'required_scopes' => ['cases:read'],
            'service_context_allowed' => ['human', 'service'],

            'auth' => true,
            'tenant_scoped' => true,
            'case_scoped' => true,

            'roles_any' => [
                'system_admin',
                'tenant_admin',
                'legal_admin',
                'attorney',
                'paralegal',
                'pro_se',
                'client',
            ],
        ],

        'GET /cases/{id}/legal-actions/{legal_action_id}/filing-documents/{document_type}/binary' => [
            'required_scopes' => ['cases:read'],
            'service_context_allowed' => ['human', 'service'],

            'auth' => true,
            'tenant_scoped' => true,
            'case_scoped' => true,

            'roles_any' => [
                'system_admin',
                'tenant_admin',
                'legal_admin',
                'attorney',
                'paralegal',
                'pro_se',
                'client',
            ],
        ],



        'POST /cases/{id}/legal-actions/{legal_action_id}/generate-documents' => [
            'required_scopes' => ['cases:write'],

            'service_context_allowed' => ['human', 'service'],

            'auth' => true,

            'mutates_state' => true,

            'tenant_scoped' => true,

            'case_scoped' => true,

            /*
            ------------------------------------------------------------
            Idempotent generation
            ------------------------------------------------------------
            Safe to retry without duplicating documents.
            */

            'idempotent' => true,

            /*
            ------------------------------------------------------------
            Freeze behavior
            ------------------------------------------------------------
            Document generation occurs AFTER commit.
            Must respect case freeze.
            */

            'ignore_case_freeze' => false,

            /*
            ------------------------------------------------------------
            Authorized roles
            ------------------------------------------------------------
            */

            'roles_any' => [
                'system_admin',
                'tenant_admin',
                'legal_admin',
                'attorney',
                'paralegal',
                'pro_se',
            ],
        ],
        
        // Boundary auto-update policies

        'POST /admin/jurisdiction-boundaries/update' => [
            'required_roles_any' => ['system_admin', 'tenant_admin', 'internal_staff'],
        ],
        'GET /admin/jurisdiction-boundaries/update-runs' => [
            'required_roles_any' => ['system_admin', 'tenant_admin', 'internal_staff'],
        ],
        'GET /admin/jurisdiction-boundaries/update-runs/{id}' => [
            'required_roles_any' => ['system_admin', 'tenant_admin', 'internal_staff'],
        ],

        'POST /cases/{id}/legal-actions/{legal_action_id}/ready-to-file' => [
            'required_scopes' => ['cases:read'],
            'service_context_allowed' => ['human', 'service'],
            'idempotent' => true,
        ],

        /* Exhibit Workspace */

        'POST /cases/{id}/exhibit-workspaces' => [
            'required_scopes' => ['document:write'],
            'service_context_allowed' => ['human'],
        ],

        'GET /exhibit-workspaces/{workspace_id}' => [
            'required_scopes' => ['document:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'POST /exhibit-workspaces/{workspace_id}/contributions' => [
            'required_scopes' => ['document:write'],
            'service_context_allowed' => ['human'],
        ],

        'POST /exhibit-workspaces/{workspace_id}/lock' => [
            'required_scopes' => ['document:finalize'],
            'service_context_allowed' => ['human'],
        ],

        'POST /exhibit-workspaces/{workspace_id}/bundles' => [
            'required_scopes' => ['document:finalize'],
            'service_context_allowed' => ['human'],
        ],

        'GET /exhibit-bundles/{bundle_id}' => [
            'required_scopes' => ['document:read'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'GET /exhibit-bundles/{bundle_id}/export' => [
            'required_scopes' => ['document:export'],
            'service_context_allowed' => ['human', 'service'],
        ],

        'POST /cases/{id}/legal-actions/{legal_action_id}/commit-filing' => [
            'required_scopes' => ['cases:write'],
            'service_context_allowed' => ['human'],
            'mutates_state' => true,
            'idempotent' => true,
            'ignore_case_freeze' => true, // allow commit even though freeze will activate
        ],

    ];


    /* ============================================================
     * Middleware entrypoint
     * ============================================================ */
    public function handle(array $req, callable $next): array
    {
        // Allow CORS preflight
        if (($req['method'] ?? '') === 'OPTIONS') {
            return [
                'status' => 204,
                'headers' => [
                    'Access-Control-Allow-Origin'  => '*',
                    'Access-Control-Allow-Methods' => 'GET, POST, PATCH, DELETE, OPTIONS',
                    'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
                    'Access-Control-Allow-Credentials' => 'true',
                ],
                'body' => null,
            ];
        }

        $endpoint = $req['canonical_endpoint'] ?? null;
        $policy   = $endpoint ? (self::POLICIES[$endpoint] ?? null) : null;

        if ($policy === null) {
            return $this->json(404, ['error' => 'NOT_FOUND']);
        }

        /* ============================================================
         * Phase 11 – External Evidence Access
         * ============================================================ */
        if (!empty($policy['evidence_access'])) {
            $ctx = $this->authenticateEvidenceAccess($req);
            if ($ctx === null) {
                return $this->json(404, ['error' => 'NOT_FOUND']);
            }

            // Optional audit (fail-soft)
            if ($this->audit !== null) {
                try {
                    $this->audit->emitAccess($req, $ctx, (string)$endpoint, [
                        'mode' => 'external',
                        'export_id' => $req['params']['export_id'] ?? null,
                        'grant_id'  => $ctx->claims['evidence_grant_id'] ?? null,
                    ]);
                } catch (Throwable) {
                    error_log('AUDIT_EMITTER_FAILED: external evidence access');
                }
            }

            // 🚨 DO NOT APPLY TENANT ISOLATION
            return $next($req, $ctx);
        }

        /* ============================================================
         * Public endpoints
         * ============================================================ */
        if (!empty($policy['public'])) {
            return $next($req, null);
        }

        /* ============================================================
         * JWT authentication
         * ============================================================ */
        try {
            $ctx = $this->authenticate($req);
        } catch (ExpiredException) {
            return $this->json(401, ['error' => 'TOKEN_EXPIRED']);
        } catch (Throwable) {
            return $this->json(401, ['error' => 'AUTH_REQUIRED']);
        }

        /* ============================================================
         * Tenant isolation (internal only)
         * ============================================================ */
        try {
            if (!$this->assertTenantIsolation($req, $ctx, (string)$endpoint)) {
                return $this->json(404, ['error' => 'NOT_FOUND']);
            }
        } catch (Throwable) {
            return $this->json(404, ['error' => 'NOT_FOUND']);
        }

        /* ============================================================
        * Case freeze enforcement (Phase 19)
        * ============================================================ */
        if (!empty($policy['mutates_state']) && empty($policy['ignore_case_freeze'])) {
            try {
                $caseId = $this->resolveCaseIdForFreezeGuard($req, (string)$endpoint, (string)$ctx->tenantId);

                // If we can resolve a caseId, enforce freeze.
                if ($caseId !== null) {
                    $this->caseMutationGuard->assertCaseMutable((string)$ctx->tenantId, $caseId);
                }
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'CASE_FROZEN') {
                    return $this->json(409, ['error' => 'CASE_FROZEN']);
                }
                throw $e;
            }
        }        

        /* ============================================================
        * Legal Action Scope Enforcement (Phase 20 safeguard)
        * ============================================================ */

        if (!empty($policy['case_scoped']) && !empty($req['params']['legal_action_id'])) {

            if (!$this->verifyLegalActionScope(
                $this->db,
                $ctx->tenantId,
                $req['params']['id'],
                $req['params']['legal_action_id']
            )) {
                return $this->json(403, ['error' => 'LEGAL_ACTION_SCOPE_VIOLATION']);
            }
        }

        /* ============================================================
         * Service-context enforcement
         * ============================================================ */
        if (!empty($policy['service_context_allowed'])) {
            $allowed = (array)$policy['service_context_allowed'];
            if (!in_array((string)$ctx->serviceContext, $allowed, true)) {
                return $this->json(403, ['error' => 'FORBIDDEN']);
            }
        }

        /* ============================================================
         * Role enforcement (ANY)
         * ============================================================ */
        if (!empty($policy['required_roles_any'])) {
            $required = (array)$policy['required_roles_any'];
            $roles = $ctx->roles ?? [];

            $ok = false;
            foreach ($required as $r) {
                if (in_array($r, $roles, true)) {
                    $ok = true;
                    break;
                }
            }

            if (!$ok) {
                return $this->json(403, ['error' => 'FORBIDDEN']);
            }
        }

        /* ============================================================
         * Scope enforcement
         * ============================================================ */
        if (!empty($policy['required_scopes'])) {
            if (!$this->scopeGuard->hasAll($ctx->scopes, (array)$policy['required_scopes'])) {
                return $this->json(403, ['error' => 'FORBIDDEN']);
            }
        }

        return $next($req, $ctx);
    }

    private function resolveCaseIdForFreezeGuard(
        array $req,
        string $endpoint,
        string $tenantId
    ): ?string {
        $params = $req['params'] ?? [];

        /*
        * IMPORTANT:
        * $endpoint is your canonical endpoint string,
        * e.g. "POST /cases/{id}/documents"
        */

        /* ============================================================
        * 1) Direct case-scoped routes
        *    POST /cases/{id}/...
        * ============================================================ */
        if (isset($params['id']) && is_string($params['id'])) {
            if (str_starts_with($endpoint, 'POST /cases/{id}')
                || str_starts_with($endpoint, 'PATCH /cases/{id}')
                || str_starts_with($endpoint, 'DELETE /cases/{id}')
            ) {
                return $params['id'];
            }
        }

        /* ============================================================
        * 2) Case-document scoped routes
        *    POST /case-documents/{id}/...
        * ============================================================ */
        if (isset($params['id']) && is_string($params['id'])) {
            if (str_starts_with($endpoint, 'POST /case-documents/{id}')
                || str_starts_with($endpoint, 'PATCH /case-documents/{id}')
                || str_starts_with($endpoint, 'DELETE /case-documents/{id}')
            ) {
                return $this->caseMutationGuard
                    ->resolveCaseIdFromCaseDocumentId($tenantId, $params['id']);
            }
        }

        /* ============================================================
        * 3) Legal-action scoped routes
        *    POST /cases/{id}/legal-actions/{legal_action_id}/...
        * ============================================================ */
        if (isset($params['legal_action_id']) && is_string($params['legal_action_id'])) {
            // These endpoints already include case_id in {id},
            // but this safely resolves even if structure changes later.
            return $this->caseMutationGuard
                ->resolveCaseIdFromLegalActionId($tenantId, $params['legal_action_id']);
        }

        /* ============================================================
        * 4) Explicit case_id param support
        * ============================================================ */
        if (isset($params['case_id']) && is_string($params['case_id'])) {
            return $params['case_id'];
        }

        /* ============================================================
        * 5) Exhibit workspace scoped routes (future-proofing)
        * ============================================================ */
        if (isset($params['workspace_id']) && is_string($params['workspace_id'])) {
            return $this->caseMutationGuard
                ->resolveCaseIdFromExhibitWorkspaceId($tenantId, $params['workspace_id']);
        }

        /*
        * Everything else:
        * - property endpoints
        * - admin endpoints
        * - global reference endpoints
        *
        * These should NOT trigger freeze enforcement.
        */
        return null;
    }
    /* ============================================================
     * Phase 11 evidence token authentication
     * ============================================================ */
    private function authenticateEvidenceAccess(array $req): ?AuthContext
    {
        $headers = $this->normalizeHeaders($req['headers'] ?? []);
        $token = $this->extractEvidenceToken($headers);

        if ($token === null) return null;
        if (!preg_match('/^[A-Za-z0-9_-]{20,200}$/', $token)) return null;

        $tokenHashHex = hash('sha256', $token);
        $tokenHashWithPrefix = 'sha256:' . $tokenHashHex;

        $sql = <<<SQL
        SELECT
            id,
            tenant_id,
            document_export_id,
            subject,
            purpose,
            issued_at,
            expires_at,
            revoked_at
        FROM evidence_access_grants
        WHERE (token_hash = :hex OR token_hash = :pref)
        AND revoked_at IS NULL
        AND (expires_at IS NULL OR expires_at > now())
        SQL;

        $st = $this->db->prepare($sql);
        $st->execute([
            ':hex'  => $tokenHashHex,
            ':pref' => $tokenHashWithPrefix,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        return new AuthContext(
            tenantId: (string)$row['tenant_id'],
            actorId: (string)$row['id'],
            actorType: 'external',
            serviceContext: 'external',
            scopes: ['evidence:consume'],
            roles: [],
            jti: null,
            claims: [
                'evidence_grant_id'   => $row['id'],
                'evidence_export_id'  => $row['document_export_id'],
                'evidence_subject'    => $row['subject'],
                'evidence_purpose'    => $row['purpose'],
                'evidence_issued_at'  => $row['issued_at'],
                'evidence_expires_at' => $row['expires_at'],
            ]
        );
    }

    /* ============================================================
     * JWT authentication
     * ============================================================ */
    private function authenticate(array $req): AuthContext
    {
        $headers = $req['headers'] ?? [];
        $authz = $headers['Authorization'] ?? $headers['authorization'] ?? null;

        if (!is_string($authz) || !preg_match('/^Bearer\s+(.+)$/i', trim($authz), $m)) {
            throw new RuntimeException('AUTH_REQUIRED');
        }

        $claims = $this->tokenVerifier->verify($m[1]);

        return new AuthContext(
            tenantId: (string)($claims['tenant_id'] ?? ''),
            actorId: (string)($claims['actor_id'] ?? ''),
            actorType: (string)($claims['actor_type'] ?? ''),
            serviceContext: (string)($claims['service_context'] ?? ''),
            scopes: (array)($claims['scopes'] ?? []),
            roles: (array)($claims['roles'] ?? []),
            jti: $claims['jti'] ?? null,
            claims: $claims
        );
    }

    /* ============================================================
     * Tenant isolation (controller-enforced)
     * ============================================================ */
    private function assertTenantIsolation(array $req, AuthContext $ctx, string $endpoint): bool
    {
        return true;
    }

    private function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            if (is_string($k)) {
                $out[strtolower($k)] = $v;
            }
        }
        return $out;
    }

    private function extractEvidenceToken(array $headersLower): ?string
    {
        if (!empty($headersLower['x-evidence-access-token'])) {
            return trim((string)$headersLower['x-evidence-access-token']);
        }

        if (!empty($headersLower['authorization']) &&
            preg_match('/^Evidence\s+(\S+)$/i', trim((string)$headersLower['authorization']), $m)) {
            return $m[1];
        }

        return null;
    }

    private function json(int $status, array $body): array
    {
        return [
            'status'  => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $body,
        ];
    }

    private function verifyLegalActionScope(
        PDO $db,
        string $tenantId,
        string $caseId,
        string $legalActionId
    ): bool {

        $st = $db->prepare("
            SELECT 1
            FROM legal_actions
            WHERE tenant_id = :tenant
            AND case_id = :case_id
            AND id = :legal_action_id
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case_id' => $caseId,
            ':legal_action_id' => $legalActionId
        ]);

        return (bool)$st->fetchColumn();
    }
}
