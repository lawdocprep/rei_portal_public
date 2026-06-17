<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Services\PhaseActionResolver;
use App\Services\ProceduralStateService;
use PDO;

/**
 * Phase 16.3:
 * Court data is surfaced for advisory context only.
 * It must not be used to enforce eligibility, jurisdiction,
 * filing readiness, or procedural advancement.
 */
final class EligibleActionsController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * GET /cases/{id}/eligible-actions
     * ============================================================ */
    public function get(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $caseId = $req['params']['id'] ?? null;
        if (!is_string($caseId) || $caseId === '') {
            return $this->badRequest('INVALID_CASE_ID');
        }

        $tenantId = (string)$ctx->tenantId;
        $roles    = $ctx->roles ?? [];

        /*
         * Resolve eligibility exactly as before
         */
        $procedural = new ProceduralStateService($this->db);
        $resolver   = new PhaseActionResolver($this->db, $procedural);

        $out = $resolver->resolve($tenantId, $caseId, $roles);

        if (isset($out['error'])) {
            if ($out['error'] === 'CASE_NOT_FOUND') {
                return $this->json(404, ['error' => 'CASE_NOT_FOUND']);
            }
            return $this->json(400, ['error' => $out['error']]);
        }

        /*
         * Phase 16.3 — read-only court context
         * This MUST NOT affect eligibility logic.
         */
        $courtContext = $this->loadCourtContext($tenantId, $caseId);

        if ($courtContext !== null) {
            $out['court_context'] = $courtContext;
        }

        return [
            'status'  => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $out,
        ];
    }

    /**
     * Load court snapshot for advisory context only.
     * No validation. No enforcement. No side effects.
     */
    private function loadCourtContext(string $tenantId, string $caseId): ?array
    {
        $st = $this->db->prepare("
            SELECT
                la.court_name,
                la.court_type,
                la.court_city,
                la.court_state,
                la.court_county,
                la.court_case_number
            FROM legal_actions la
            WHERE la.tenant_id = :tenant
              AND la.case_id   = :case
            ORDER BY la.created_at DESC
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $hasAny =
            !empty($row['court_name']) ||
            !empty($row['court_type']) ||
            !empty($row['court_city']) ||
            !empty($row['court_state']) ||
            !empty($row['court_county']) ||
            !empty($row['court_case_number']);

        if (!$hasAny) {
            return null;
        }

        return [
            'snapshot' => [
                'court_name'        => $row['court_name'],
                'court_type'        => $row['court_type'],
                'court_city'        => $row['court_city'],
                'court_state'       => $row['court_state'],
                'court_county'      => $row['court_county'],
                'court_case_number' => $row['court_case_number'],
            ],
            'note' => 'Court information is informational and not validated.',
            'binding' => false,
        ];
    }

    /* ============================================================
     * Helpers
     * ============================================================ */

    private function unauthorized(): array
    {
        return $this->json(401, ['error' => 'AUTH_REQUIRED']);
    }

    private function badRequest(string $code): array
    {
        return $this->json(400, ['error' => $code]);
    }

    private function json(int $status, array $body): array
    {
        return [
            'status'  => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $body,
        ];
    }
}
