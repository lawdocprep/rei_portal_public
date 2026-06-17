<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;

final class FilingBundleController
{
    public function __construct(
        private PDO $db
    ) {}

    /**
     * GET /cases/{id}/legal-actions/{legal_action_id}/filing-bundle
     */
    public function get(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $tenantId = (string)$ctx->tenantId;
        $caseId = $req['params']['id'] ?? null;
        $legalActionId = $req['params']['legal_action_id'] ?? null;

        if (!$this->isUuid($caseId) || !$this->isUuid($legalActionId)) {
            return $this->badRequest('INVALID_IDENTIFIER');
        }

        $st = $this->db->prepare("
            SELECT
                id,
                tenant_id,
                case_id,
                legal_action_id,
                intent_hash,
                structured_artifacts_json,
                structural_validation_json,
                version,
                frozen,
                created_at
            FROM filing_bundles
            WHERE tenant_id = :tenant
              AND case_id = :case_id
              AND legal_action_id = :laid
              AND frozen = true
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case_id' => $caseId,
            ':laid' => $legalActionId,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return $this->notFound('FILING_BUNDLE_NOT_FOUND');
        }

        return $this->json(200, [
            'case_id' => $row['case_id'],
            'legal_action_id' => $row['legal_action_id'],
            'status' => 'prepared_for_filing',
            'filing_bundle' => [
                'id' => $row['id'],
                'intent_hash' => $row['intent_hash'],
                'structured_artifacts' => json_decode((string)$row['structured_artifacts_json'], true),
                'structural_validation' => json_decode((string)$row['structural_validation_json'], true),
                'version' => (int)$row['version'],
                'frozen' => (bool)$row['frozen'],
                'created_at' => $row['created_at'],
            ]
        ]);
    }

    /* ============================================================ */

    private function json(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }

    private function badRequest(string $code): array
    {
        return $this->json(400, ['error' => $code]);
    }

    private function unauthorized(): array
    {
        return $this->json(401, ['error' => 'UNAUTHORIZED']);
    }

    private function notFound(string $code): array
    {
        return $this->json(404, ['error' => $code]);
    }

    private function isUuid(?string $value): bool
    {
        if (!$value) return false;
        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }
}