<?php
declare(strict_types=1);

namespace App\Services\Exhibits;

use PDO;
use App\Services\Validation\ArtifactPolicyRegistry;

final class ExhibitLinkageService
{
    public function __construct(
        private PDO $db
    ) {}

    /**
     * Materialize deterministic exhibit linkage for an artifact.
     *
     * Idempotent:
     * - Safe to call repeatedly.
     * - Existing linkage rows for same intent_hash are replaced atomically.
     */
    public function materialize(
        string $tenantId,
        string $caseId,
        string $legalActionId,
        string $actionType,
        string $artifactType,
        string $intentHash
    ): array {

        $rules = ArtifactPolicyRegistry::getArtifactRules($actionType, $artifactType);

        if (!is_array($rules)) {
            return [];
        }

        $requiredDefs = $rules['required_exhibits'] ?? [];

        $documents = $this->loadFinalizedDocuments($tenantId, $caseId);

        $selected = $this->selectDocuments($documents, $requiredDefs);

        $sorted = $this->sortDeterministically($selected);

        $snapshot = $this->assignLabels($sorted);

        $this->persist(
            $tenantId,
            $caseId,
            $legalActionId,
            $actionType,
            $artifactType,
            $intentHash,
            $snapshot
        );

        return $snapshot;
    }

    /* ============================================================
     * Load finalized documents
     * ============================================================ */

    private function loadFinalizedDocuments(string $tenantId, string $caseId): array
    {
        $sql = "
            SELECT
                cd.id AS case_document_id,
                cd.document_type_key,
                cd.title,
                cd.is_evidence,
                cd.final_version_id,
                dv.content_hash
            FROM case_documents cd
            JOIN document_versions dv
                ON dv.id = cd.final_version_id
            WHERE cd.tenant_id = :tenant
              AND cd.case_id = :case
              AND cd.status = 'final'
              AND cd.final_version_id IS NOT NULL
        ";

        $st = $this->db->prepare($sql);
        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
        ]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /* ============================================================
     * Select documents based on policy
     * ============================================================ */

    private function selectDocuments(array $documents, array $requiredDefs): array
    {
        $selected = [];
        $docMap   = [];

        foreach ($documents as $doc) {
            $docMap[$doc['case_document_id']] = $doc;
        }

        /* ------------------------------
         * Required exhibits
         * ------------------------------ */

        foreach ($requiredDefs as $def) {

            if (empty($def['required']) || empty($def['document_type_keys'])) {
                continue;
            }

            $allowedKeys = $def['document_type_keys'];

            foreach ($documents as $doc) {

                if (in_array($doc['document_type_key'], $allowedKeys, true)) {

                    $selected[] = [
                        'exhibit_key'             => $def['exhibit_key'],
                        'title'                   => $def['title'] ?? $doc['title'],
                        'source_case_document_id' => $doc['case_document_id'],
                        'source_version_id'       => $doc['final_version_id'],
                        'content_hash'            => $doc['content_hash'],
                        'required'                => true,
                    ];

                    break; // Only one document satisfies required key
                }
            }
        }

        /* ------------------------------
         * Optional evidence documents
         * ------------------------------ */

        foreach ($documents as $doc) {

            if (!$doc['is_evidence']) {
                continue;
            }

            // Avoid duplicating required selections
            $alreadySelected = false;

            foreach ($selected as $s) {
                if ($s['source_case_document_id'] === $doc['case_document_id']) {
                    $alreadySelected = true;
                    break;
                }
            }

            if ($alreadySelected) {
                continue;
            }

            $selected[] = [
                'exhibit_key'             => $doc['document_type_key'],
                'title'                   => $doc['title'],
                'source_case_document_id' => $doc['case_document_id'],
                'source_version_id'       => $doc['final_version_id'],
                'content_hash'            => $doc['content_hash'],
                'required'                => false,
            ];
        }

        return $selected;
    }

    /* ============================================================
     * Deterministic sort
     * ============================================================ */

    private function sortDeterministically(array $items): array
    {
        usort($items, function ($a, $b) {

            // Required first
            if ($a['required'] !== $b['required']) {
                return $a['required'] ? -1 : 1;
            }

            // Then exhibit_key
            $cmp = strcmp($a['exhibit_key'], $b['exhibit_key']);
            if ($cmp !== 0) {
                return $cmp;
            }

            // Then content_hash
            $cmp = strcmp($a['content_hash'], $b['content_hash']);
            if ($cmp !== 0) {
                return $cmp;
            }

            // Finally document id
            return strcmp(
                $a['source_case_document_id'],
                $b['source_case_document_id']
            );
        });

        return array_values($items);
    }

    /* ============================================================
     * Assign labels + sort_order
     * ============================================================ */

    private function assignLabels(array $items): array
    {
        foreach ($items as $i => &$item) {

            $item['sort_order'] = $i;
            $item['exhibit_label'] = 'Exhibit ' . ($i + 1);
        }

        return $items;
    }

    /* ============================================================
     * Persist linkage rows (idempotent)
     * ============================================================ */

    private function persist(
        string $tenantId,
        string $caseId,
        string $legalActionId,
        string $actionType,
        string $artifactType,
        string $intentHash,
        array $snapshot
    ): void {

        // ------------------------------------------------------------
        // Remove ALL prior linkage for this artifact_type
        // (do NOT scope by intent_hash — uniqueness does not include it)
        // ------------------------------------------------------------
        $del = $this->db->prepare("
            DELETE FROM artifact_exhibit_links
            WHERE tenant_id = :tenant
            AND case_id = :case_id
            AND legal_action_id = :legal_action
            AND artifact_type = :artifact
        ");

        $del->execute([
            ':tenant'       => $tenantId,
            ':case_id'      => $caseId,
            ':legal_action' => $legalActionId,
            ':artifact'     => $artifactType,
        ]);

        // ------------------------------------------------------------
        // Insert deterministic snapshot rows (UPSERT-safe)
        // ------------------------------------------------------------
        $ins = $this->db->prepare("
            INSERT INTO artifact_exhibit_links (
                tenant_id,
                case_id,
                legal_action_id,
                action_type,
                artifact_type,
                intent_hash,
                exhibit_key,
                exhibit_label,
                title,
                source_case_document_id,
                source_version_id,
                content_hash,
                required,
                sort_order,
                created_at
            )
            VALUES (
                :tenant,
                :case,
                :legal_action,
                :action_type,
                :artifact,
                :hash,
                :key,
                :label,
                :title,
                :case_doc,
                :version_id,
                :hash_value,
                :required,
                :sort_order,
                now()
            )
            ON CONFLICT (tenant_id, case_id, legal_action_id, artifact_type, exhibit_key)
            DO UPDATE SET
                action_type = EXCLUDED.action_type,
                intent_hash = EXCLUDED.intent_hash,
                exhibit_label = EXCLUDED.exhibit_label,
                title = EXCLUDED.title,
                source_case_document_id = EXCLUDED.source_case_document_id,
                source_version_id = EXCLUDED.source_version_id,
                content_hash = EXCLUDED.content_hash,
                required = EXCLUDED.required,
                sort_order = EXCLUDED.sort_order
        ");

        foreach ($snapshot as $row) {

            $ins->execute([
                ':tenant'       => $tenantId,
                ':case'         => $caseId,
                ':legal_action' => $legalActionId,
                ':action_type'  => $actionType,
                ':artifact'     => $artifactType,
                ':hash'         => $intentHash,
                ':key'          => $row['exhibit_key'],
                ':label'        => $row['exhibit_label'],
                ':title'        => $row['title'],
                ':case_doc'     => $row['source_case_document_id'],
                ':version_id'   => $row['source_version_id'],
                ':hash_value'   => $row['content_hash'],
                ':required'     => $row['required'] ? 1 : 0,
                ':sort_order'   => (int)$row['sort_order'],
            ]);
        }

        // Transaction commit handled by caller.
    }
}