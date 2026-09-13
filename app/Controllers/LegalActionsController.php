<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Services\Events\CaseEventService;
use PDO;
use RuntimeException;

final class LegalActionsController
{
    private CaseEventService $events;

    public function __construct(
        private PDO $db,
        ?CaseEventService $events = null
    ) {
        $this->events = $events ?? new CaseEventService($db);
    }

    /**
     * POST /cases/{id}/actions/{action_type}/initiate
     */
    public function initiate(array $req, AuthContext $ctx): array
    {
        $tenantId   = (string)$ctx->tenantId;
        $actorId    = (string)$ctx->actorId;
        $caseId     = (string)$req['case_id'];
        $actionType = (string)$req['params']['action_type'];

        $body = is_array($req['body'] ?? null)
            ? $req['body']
            : [];

        $origin = $body['origin'] ?? null;
        if (!$origin) {
            throw new RuntimeException('ORIGIN_REQUIRED');
        }

        $parentId = $body['parent_legal_action_id'] ?? null;

        $filingAttorneyActorId =
            $body['filing_attorney_actor_id'] ?? null;

        if (
            $filingAttorneyActorId !== null
            && (
                !is_string($filingAttorneyActorId)
                || !$this->isUuid($filingAttorneyActorId)
            )
        ) {
            throw new RuntimeException(
                'FILING_ATTORNEY_ACTOR_ID_INVALID'
            );
        }

        /*
         * Validate parent legal action
         */
        if ($parentId !== null) {
            $st = $this->db->prepare("
                SELECT 1
                FROM legal_actions
                WHERE id = :id
                  AND tenant_id = :tenant
                  AND case_id = :case
            ");
            $st->execute([
                ':id'     => $parentId,
                ':tenant' => $tenantId,
                ':case'   => $caseId,
            ]);

            if (!$st->fetchColumn()) {
                throw new RuntimeException('PARENT_LEGAL_ACTION_INVALID');
            }
        }

        /*
         * Idempotency check
         */
        $st = $this->db->prepare("
            SELECT *
            FROM legal_actions
            WHERE tenant_id = :tenant
              AND case_id = :case
              AND action_type = :action_type
              AND (
                    parent_legal_action_id = :parent
                 OR (parent_legal_action_id IS NULL AND :parent IS NULL)
              )
            LIMIT 1
        ");
        $st->execute([
            ':tenant'      => $tenantId,
            ':case'        => $caseId,
            ':action_type' => $actionType,
            ':parent'      => $parentId,
        ]);

        $existing = $st->fetch(PDO::FETCH_ASSOC);

        if ($existing) {

            $existingAttorneyId =
                $existing['filing_attorney_actor_id'] ?? null;

            /*
            * Idempotent retries must not silently change an immutable
            * filing-attorney assignment.
            */
            if (
                $filingAttorneyActorId !== null
                && $existingAttorneyId !== $filingAttorneyActorId
            ) {
                throw new RuntimeException(
                    'LEGAL_ACTION_FILING_ATTORNEY_CONFLICT'
                );
            }

            return $this->formatResponse($existing);
        }

        /*
         * Validate filing attorney before legal action creation.
         *
         * legal_actions becomes immutable once initiated, so the filing
         * attorney must be established as part of the original INSERT.
         */
        if ($filingAttorneyActorId !== null) {
            $st = $this->db->prepare("
                SELECT 1
                FROM actors a
                JOIN attorney_filing_profiles afp
                  ON afp.actor_id = a.id
                 AND afp.tenant_id = a.tenant_id
                WHERE a.id = :actor_id
                  AND a.tenant_id = :tenant_id
                  AND afp.effective_from <= CURRENT_TIMESTAMP
                  AND (
                        afp.effective_to IS NULL
                        OR afp.effective_to > CURRENT_TIMESTAMP
                      )
                ORDER BY afp.effective_from DESC, afp.id DESC
                LIMIT 1
            ");

            $st->execute([
                ':actor_id'  => $filingAttorneyActorId,
                ':tenant_id' => $tenantId,
            ]);

            if (!$st->fetchColumn()) {
                throw new RuntimeException(
                    'FILING_ATTORNEY_INVALID_OR_INACTIVE'
                );
            }
        }

        /*
         * Create legal action
         */
        $court = is_array($body['court'] ?? null)
            ? $body['court']
            : [];

        $st = $this->db->prepare("
            INSERT INTO legal_actions (
                tenant_id,
                case_id,
                action_type,
                status,
                initiated_at,
                created_by,
                filing_attorney_actor_id,
                parent_legal_action_id,
                origin,
                court_name,
                court_type,
                court_city,
                court_state,
                court_county,
                court_case_number,
                created_at
            ) VALUES (
                :tenant,
                :case,
                :action_type,
                'initiated',
                NOW(),
                :actor,
                :filing_attorney_actor_id,
                :parent,
                :origin,
                :court_name,
                :court_type,
                :court_city,
                :court_state,
                :court_county,
                :court_case_number,
                NOW()
            )
            RETURNING id
        ");

        $st->execute([
            ':tenant'                     => $tenantId,
            ':case'                       => $caseId,
            ':action_type'                => $actionType,
            ':actor'                      => $actorId,
            ':filing_attorney_actor_id'   => $filingAttorneyActorId,
            ':parent'                     => $parentId,
            ':origin'                     => $origin,
            ':court_name'                 => $court['name'] ?? null,
            ':court_type'                 => $court['type'] ?? null,
            ':court_city'                 => $court['city'] ?? null,
            ':court_state'                => $court['state'] ?? null,
            ':court_county'               => $court['county'] ?? null,
            ':court_case_number'          => $court['case_number'] ?? null,
        ]);

        $id = (string)$st->fetchColumn();

        /*
         * Emit event via CaseEventService
         */
        $this->events->emitEvent(
            'legal_action.initiated',
            [
                'legal_action_id' => $id,
                'case_id' => $caseId,
                'action_type' => $actionType,
                'origin' => $origin,
                'filing_attorney_actor_id' => $filingAttorneyActorId,
                'parent_legal_action_id' => $parentId,
                'court' => $court,
            ],
            $tenantId,
            $caseId,
            $actorId
        );

        return [
            'status' => 201,
            'body' => [
                'legal_action_id' => $id,
                'case_id' => $caseId,
                'action_type' => $actionType,
                'action_status' => 'initiated',
                'origin' => $origin,
                'filing_attorney_actor_id' => $filingAttorneyActorId,
                'parent_legal_action_id' => $parentId,
                'court' => [
                    'name' => $court['name'] ?? null,
                    'type' => $court['type'] ?? null,
                    'city' => $court['city'] ?? null,
                    'state' => $court['state'] ?? null,
                    'county' => $court['county'] ?? null,
                    'case_number' => $court['case_number'] ?? null,
                ],
                'initiated_at' => date('c'),
            ],
        ];
    }

    /**
     * GET /cases/{id}/legal-actions
     */
    public function list(array $req, AuthContext $ctx): array
    {
        $tenantId = (string)$ctx->tenantId;
        $caseId   = (string)$req['case_id'];

        $st = $this->db->prepare("
            SELECT
                id,
                case_id,
                action_type,
                status,
                initiated_at,
                filing_attorney_actor_id,
                parent_legal_action_id,
                origin,
                court_name,
                court_type,
                court_city,
                court_state,
                court_county,
                court_case_number
            FROM legal_actions
            WHERE tenant_id = :tenant
              AND case_id = :case
            ORDER BY initiated_at ASC
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
        ]);

        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return [
            'status' => 200,
            'body' => [
                'case_id' => $caseId,
                'legal_actions' => array_map(
                    function (array $row): array {
                        return [
                            'legal_action_id' => $row['id'],
                            'action_type' => $row['action_type'],
                            'action_status' => $row['status'],
                            'origin' => $row['origin'],
                            'filing_attorney_actor_id' =>
                                $row['filing_attorney_actor_id'] ?? null,
                            'parent_legal_action_id' =>
                                $row['parent_legal_action_id'],
                            'initiated_at' => $row['initiated_at'],
                            'court' => $this->buildCourtSnapshot($row),
                        ];
                    },
                    $rows
                ),
            ],
        ];
    }

    private function buildCourtSnapshot(array $row): ?array
    {
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
            'court_name'         => $row['court_name'],
            'court_type'         => $row['court_type'],
            'court_city'         => $row['court_city'],
            'court_state'        => $row['court_state'],
            'court_county'       => $row['court_county'],
            'court_case_number'  => $row['court_case_number'],
            'informational_only' => true,
            'validated'          => false,
        ];
    }

    private function formatResponse(array $row): array
    {
        return [
            'status' => 200,
            'body' => [
                'legal_action_id' => $row['id'],
                'case_id' => $row['case_id'],
                'action_type' => $row['action_type'],
                'action_status' => $row['status'],
                'origin' => $row['origin'],
                'filing_attorney_actor_id' =>
                    $row['filing_attorney_actor_id'] ?? null,
                'parent_legal_action_id' =>
                    $row['parent_legal_action_id'],
                'court' => $this->buildCourtSnapshot($row),
                'initiated_at' => $row['initiated_at'] ?? null,
            ],
        ];
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
            $value
        ) === 1;
    }
}
