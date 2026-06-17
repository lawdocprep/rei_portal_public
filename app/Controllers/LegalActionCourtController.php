<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Services\Guard\CaseMutationGuard;
use PDO;
use RuntimeException;

final class LegalActionCourtController
{
    public function __construct(
        private PDO $db
    ) {}

    public function setCourt(array $req, AuthContext $ctx): array
    {
        $tenantId      = (string)$ctx->tenantId;
        $actorId       = (string)$ctx->actorId;
        $caseId        = $req['params']['case_id'] ?? null;
        $legalActionId = $req['params']['legal_action_id'] ?? null;
        $body          = $req['body'] ?? [];

        if (!$this->isUuid($caseId) || !$this->isUuid($legalActionId)) {
            return $this->json(400, ['error' => 'IDENTIFIER_REQUIRED']);
        }

        /* ============================================================
         * 🔒 Phase 17 Freeze Guard
         * ============================================================ */
        try {
            $guard = new CaseMutationGuard($this->db);
            $guard->assertCaseMutable($tenantId, $caseId);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'CASE_FROZEN_PREPARED_FOR_FILING') {
                return $this->json(409, [
                    'error' => 'CASE_FROZEN_PREPARED_FOR_FILING'
                ]);
            }
            throw $e;
        }

        /* ============================================================
         * Load existing snapshot
         * ============================================================ */
        $st = $this->db->prepare("
            SELECT
                court_name,
                court_type,
                court_city,
                court_state,
                court_county,
                court_case_number
            FROM legal_actions
            WHERE id = :id
              AND case_id = :case
              AND tenant_id = :tenant
        ");
        $st->execute([
            ':id'     => $legalActionId,
            ':case'   => $caseId,
            ':tenant' => $tenantId,
        ]);

        $old = $st->fetch(PDO::FETCH_ASSOC);

        if (!$old) {
            return $this->json(404, ['error' => 'LEGAL_ACTION_NOT_FOUND']);
        }

        $normalize = static function (array $in): array {
            $fields = [
                'court_name',
                'court_type',
                'court_city',
                'court_state',
                'court_county',
                'court_case_number',
            ];

            $out = [];
            foreach ($fields as $f) {
                $v = $in[$f] ?? null;
                if (is_string($v)) {
                    $v = trim($v);
                    if ($v === '') {
                        $v = null;
                    }
                }
                $out[$f] = $v;
            }
            return $out;
        };

        $oldSnap = $normalize($old);
        $newSnap = $normalize(is_array($body) ? $body : []);

        /* ============================================================
         * Idempotency Guard
         * ============================================================ */
        if ($oldSnap === $newSnap) {
            return $this->json(200, [
                'legal_action_id' => $legalActionId,
                'court' => $newSnap,
            ]);
        }

        /* ============================================================
         * Update snapshot
         * ============================================================ */
        $upd = $this->db->prepare("
            UPDATE legal_actions SET
                court_name = :court_name,
                court_type = :court_type,
                court_city = :court_city,
                court_state = :court_state,
                court_county = :court_county,
                court_case_number = :court_case_number
            WHERE id = :id
              AND tenant_id = :tenant
        ");

        $upd->execute([
            ':court_name'        => $newSnap['court_name'],
            ':court_type'        => $newSnap['court_type'],
            ':court_city'        => $newSnap['court_city'],
            ':court_state'       => $newSnap['court_state'],
            ':court_county'      => $newSnap['court_county'],
            ':court_case_number' => $newSnap['court_case_number'],
            ':id'                => $legalActionId,
            ':tenant'            => $tenantId,
        ]);

        /* ============================================================
         * Emit event
         * ============================================================ */
        $evt = $this->db->prepare("
            INSERT INTO legal_action_events (
                id,
                tenant_id,
                legal_action_id,
                event_type,
                performed_by,
                payload,
                event_at
            ) VALUES (
                gen_random_uuid(),
                :tenant,
                :legal_action,
                'legal_action.court_changed',
                :actor,
                :payload::jsonb,
                NOW()
            )
        ");

        $evt->execute([
            ':tenant'       => $tenantId,
            ':legal_action' => $legalActionId,
            ':actor'        => $actorId,
            ':payload'      => json_encode([
                'legal_action_id' => $legalActionId,
                'old_court'       => $oldSnap,
                'new_court'       => $newSnap,
            ]),
        ]);

        return $this->json(200, [
            'legal_action_id' => $legalActionId,
            'court' => $newSnap,
        ]);
    }

    /* ============================================================ */

    private function json(int $status, array $body): array
    {
        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $body,
        ];
    }

    private function isUuid(?string $v): bool
    {
        if (!$v) return false;

        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $v
        );
    }
}