<?php
declare(strict_types=1);

namespace App\Services\Filing;

use App\Services\FilingReadyService;
use App\Services\Events\CaseEventService;

use PDO;
use RuntimeException;

final class FilingFreezeService
{
    private PDO $db;
    private CaseEventService $caseEvents;
    
    public function __construct(
        PDO $db,
        ?CaseEventService $caseEvents = null
    ) {
        $this->db = $db;
        $this->caseEvents = $caseEvents ?? new CaseEventService($db);
    }

    /**
     * Phase 17:
     * - Requires ready_for_filing = true (server authoritative)
     * - Creates immutable filing_bundles snapshot
     * - Transitions legal_action -> prepared_for_filing
     * - Idempotent: returns existing bundle if already prepared/frozen
     */
    public function prepareForFiling(
        string $tenantId,
        string $caseId,
        string $legalActionId,
        string $actorId
    ): array {

        error_log('DEBUG: prepareForFiling reached');

        // ------------------------------------------------------------
        // Fast-path: existing prepared bundle
        // ------------------------------------------------------------
        $existingPrepared = $this->loadPreparedBundle($tenantId, $caseId, $legalActionId);

        if ($existingPrepared !== null) {
            return [
                'case_id' => $caseId,
                'legal_action_id' => $legalActionId,
                'status' => 'prepared',
                'filing_bundle' => $existingPrepared,
                'idempotent' => true,
            ];
        }

        // ------------------------------------------------------------
        // Safe transaction start
        // ------------------------------------------------------------
        $startedTx = false;

        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $startedTx = true;
        }

        try {

            // ------------------------------------------------------------
            // 0) Case-level advisory lock (matches CaseEventService)
            // ------------------------------------------------------------
            $this->acquireCaseLock($tenantId, $caseId);

            // ------------------------------------------------------------
            // 1) Lock lifecycle row
            // ------------------------------------------------------------
            $la = $this->lockLegalActionRow($tenantId, $legalActionId);

            if ($la === null) {
                throw new RuntimeException('LEGAL_ACTION_NOT_FOUND');
            }

            if ((string)$la['case_id'] !== $caseId) {
                throw new RuntimeException('LEGAL_ACTION_CASE_MISMATCH');
            }

            $status = (string)$la['status'];

            if ($status === 'prepared_for_filing') {

                if ($startedTx) {
                    $this->db->commit();
                }

                $committed = $this->loadCommittedBundle($tenantId, $caseId, $legalActionId);

                return [
                    'case_id' => $caseId,
                    'legal_action_id' => $legalActionId,
                    'status' => 'prepared_for_filing',
                    'filing_bundle' => $committed,
                    'idempotent' => true,
                ];
            }

            if ($status !== 'initiated') {
                throw new RuntimeException('LEGAL_ACTION_STATUS_INVALID');
            }

            // ------------------------------------------------------------
            // 2) Lock case row + freeze guard
            // ------------------------------------------------------------
            $freezeCheck = $this->db->prepare("
                SELECT filing_committed_at
                FROM cases
                WHERE tenant_id = :tenant
                AND id = :case_id
                FOR UPDATE
            ");

            $freezeCheck->execute([
                ':tenant'  => $tenantId,
                ':case_id' => $caseId
            ]);

            $caseRow = $freezeCheck->fetch(PDO::FETCH_ASSOC);

            if (!$caseRow) {
                throw new RuntimeException('CASE_NOT_FOUND');
            }

            if (!empty($caseRow['filing_committed_at'])) {
                throw new RuntimeException('CASE_ALREADY_FROZEN');
            }

            // ------------------------------------------------------------
            // 3) Compute readiness snapshot
            // ------------------------------------------------------------
            $readySvc = new FilingReadyService($this->db);
            $ready = $readySvc->evaluate($tenantId, $caseId, $legalActionId);

            if (!is_array($ready) || empty($ready['ready_for_filing'])) {
                throw new RuntimeException(
                    'NOT_READY_FOR_FILING:' . json_encode($ready['blocking_reasons'] ?? [])
                );
            }

            $intent               = $ready['intent'] ?? null;
            $structuredArtifacts  = $ready['structured_artifacts'] ?? null;
            $structuralValidation = $ready['structural_validation'] ?? null;

            if (!is_array($intent) || !is_array($structuredArtifacts) || !is_array($structuralValidation)) {
                throw new RuntimeException('READY_SERVICE_MISSING_SNAPSHOT_FIELDS');
            }

            $intentHash = $this->extractIntentHashFromArtifacts($structuredArtifacts);

            if (!is_string($intentHash) || $intentHash === '') {
                throw new RuntimeException('INTENT_HASH_MISSING');
            }

            $structuredJson = json_encode($structuredArtifacts, JSON_UNESCAPED_SLASHES);

            if (!is_string($structuredJson)) {
                throw new RuntimeException('STRUCTURED_ARTIFACTS_JSON_ENCODE_FAILED');
            }

            // ------------------------------------------------------------
            // 4) Canonical exhibit linkage
            // ------------------------------------------------------------
            $st = $this->db->prepare("
                SELECT compute_exhibit_linkage_c14n_json(
                    :tenant,
                    :case_id,
                    :legal_action_id
                )
            ");

            $st->execute([
                ':tenant'          => $tenantId,
                ':case_id'         => $caseId,
                ':legal_action_id' => $legalActionId,
            ]);

            $exhibitJson = $st->fetchColumn();

            if ($exhibitJson === false) {
                throw new RuntimeException('EXHIBIT_LINKAGE_RECOMPUTE_FAILED');
            }

            $exhibit = json_decode((string)$exhibitJson, true);

            if (!is_array($exhibit)) {
                throw new RuntimeException('EXHIBIT_LINKAGE_CORRUPT');
            }

            // ------------------------------------------------------------
            // 5) Version hashes
            // ------------------------------------------------------------
            $versionHashes = $this->computeVersionHashesFromExhibits($exhibit);

            if (empty($versionHashes)) {
                throw new RuntimeException('VERSION_HASHES_MISSING');
            }

            $exhibitJsonStable = json_encode($exhibit, JSON_UNESCAPED_SLASHES);
            $versionsJson      = json_encode($versionHashes, JSON_UNESCAPED_SLASHES);

            if (!is_string($exhibitJsonStable)) {
                throw new RuntimeException('EXHIBIT_LINKAGE_JSON_ENCODE_FAILED');
            }

            if (!is_string($versionsJson)) {
                throw new RuntimeException('VERSION_HASHES_JSON_ENCODE_FAILED');
            }

            // ------------------------------------------------------------
            // 6) Compute bundle hash
            // ------------------------------------------------------------
            $st = $this->db->prepare("
                SELECT compute_bundle_hash(
                    :intent_hash,
                    CAST(:structured AS jsonb),
                    CAST(:exhibit AS jsonb),
                    CAST(:versions AS jsonb)
                )
            ");

            $st->execute([
                ':intent_hash' => $intentHash,
                ':structured'  => $structuredJson,
                ':exhibit'     => $exhibitJsonStable,
                ':versions'    => $versionsJson,
            ]);

            $bundleHash = $st->fetchColumn();

            if (!is_string($bundleHash) || $bundleHash === '') {
                throw new RuntimeException('BUNDLE_HASH_COMPUTE_FAILED');
            }

            // ------------------------------------------------------------
            // 7) Capture event cursor snapshot (under lock)
            // ------------------------------------------------------------
            $cursorSt = $this->db->prepare("
                SELECT COALESCE(MAX(case_sequence),0)
                FROM case_events
                WHERE tenant_id = :tenant
                AND case_id = :case_id
            ");

            $cursorSt->execute([
                ':tenant'  => $tenantId,
                ':case_id' => $caseId
            ]);

            $eventCursor = (int)$cursorSt->fetchColumn();

            // ------------------------------------------------------------
            // 8) Insert prepared bundle
            // ------------------------------------------------------------
            $bundleId = $this->insertFilingBundle(
                tenantId: $tenantId,
                caseId: $caseId,
                legalActionId: $legalActionId,
                intentHash: $intentHash,
                structuredArtifacts: $structuredArtifacts,
                structuralValidation: $structuralValidation,
                exhibitLinkageC14n: $exhibit,
                versionHashesC14n: $versionHashes,
                bundleHash: $bundleHash,
                eventCursor: $eventCursor
            );

            // ------------------------------------------------------------
            // 9) Emit audit event
            // ------------------------------------------------------------
            $this->emitAuditEvent(
                tenantId: $tenantId,
                caseId: $caseId,
                legalActionId: $legalActionId,
                actorId: $actorId,
                bundleId: $bundleId,
                intentHash: $intentHash,
                eventCursor: $eventCursor
            );

            if ($startedTx) {
                $this->db->commit();
            }

            $prepared = $this->loadPreparedBundle($tenantId, $caseId, $legalActionId);

            return [
                'case_id'         => $caseId,
                'legal_action_id' => $legalActionId,
                'status'          => 'prepared',
                'intent_hash'     => $intentHash,
                'bundle_hash'     => $bundleHash,
                'event_cursor'    => $eventCursor,
                'filing_bundle'   => $prepared,
                'idempotent'      => false,
            ];

        } catch (\Throwable $e) {

            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    /* ============================================================
     * Internals
     * ============================================================ */

    private function readLegalActionStatus(string $tenantId, string $legalActionId): ?string
    {
        $st = $this->db->prepare("
            SELECT status
            FROM legal_actions
            WHERE tenant_id = :tenant
              AND id = :id
            LIMIT 1
        ");
        $st->execute([
            ':tenant' => $tenantId,
            ':id' => $legalActionId,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $status = $row['status'] ?? null;
        return is_string($status) ? $status : null;
    }

    private function computeVersionHashesFromExhibits(array $snapshot): array
    {
        $out = [];

        foreach ($snapshot as $row) {

            if (
                empty($row['source_case_document_id']) ||
                empty($row['source_version_id']) ||
                empty($row['content_hash'])
            ) {
                continue;
            }

            $out[] = [
                'case_document_id' => $row['source_case_document_id'],
                'version_id'       => $row['source_version_id'],
                'content_hash'     => $row['content_hash'],
            ];
        }

        return $out;
    }

    private function loadFrozenBundle(
        string $tenantId,
        string $caseId,
        string $legalActionId
    ): ?array {
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
            ':tenant'  => $tenantId,
            ':case_id' => $caseId,
            ':laid'    => $legalActionId,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    private function lockLegalActionRow(string $tenantId, string $legalActionId): ?array
    {
        $st = $this->db->prepare("
            SELECT id, tenant_id, case_id, status
            FROM legal_actions
            WHERE tenant_id = :tenant
              AND id = :id
            FOR UPDATE
        ");
        $st->execute([
            ':tenant' => $tenantId,
            ':id' => $legalActionId,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function insertFilingBundle(
        string $tenantId,
        string $caseId,
        string $legalActionId,
        string $intentHash,
        array $structuredArtifacts,
        array $structuralValidation,
        array $exhibitLinkageC14n,
        array $versionHashesC14n,
        string $bundleHash,
        int $eventCursor
    ): string {

        // Defensive: ensure JSON encodes succeed
        $artifactsJson = json_encode($structuredArtifacts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($artifactsJson)) {
            throw new RuntimeException('STRUCTURED_ARTIFACTS_JSON_ENCODE_FAILED');
        }

        $validationJson = json_encode($structuralValidation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($validationJson)) {
            throw new RuntimeException('STRUCTURAL_VALIDATION_JSON_ENCODE_FAILED');
        }

        $exhibitsJson = json_encode($exhibitLinkageC14n, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($exhibitsJson)) {
            throw new RuntimeException('EXHIBIT_LINKAGE_JSON_ENCODE_FAILED');
        }

        $versionsJson = json_encode($versionHashesC14n, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($versionsJson)) {
            throw new RuntimeException('VERSION_HASHES_JSON_ENCODE_FAILED');
        }

        if ($bundleHash === '') {
            throw new RuntimeException('BUNDLE_HASH_MISSING');
        }

        $st = $this->db->prepare("
            INSERT INTO filing_bundles (
                tenant_id,
                case_id,
                legal_action_id,
                intent_hash,
                structured_artifacts_json,
                structural_validation_json,
                exhibit_linkage_c14n_json,
                version_hashes_c14n_json,
                bundle_hash,
                event_cursor,
                freeze_level,
                frozen,
                version
            )
            VALUES (
                :tenant,
                :case_id,
                :laid,
                :intent_hash,
                CAST(:artifacts AS jsonb),
                CAST(:validation AS jsonb),
                CAST(:exhibits AS jsonb),
                CAST(:version_hashes AS jsonb),
                :bundle_hash,
                :event_cursor,
                'prepared',
                false,
                1
            )
            RETURNING id
        ");

        $st->execute([
            ':tenant'         => $tenantId,
            ':case_id'        => $caseId,
            ':laid'           => $legalActionId,
            ':intent_hash'    => $intentHash,
            ':artifacts'      => $artifactsJson,
            ':validation'     => $validationJson,
            ':exhibits'       => $exhibitsJson,
            ':version_hashes' => $versionsJson,
            ':bundle_hash'    => $bundleHash,
            ':event_cursor'   => $eventCursor
        ]);

        $id = $st->fetchColumn();

        if (!is_string($id) || $id === '') {
            throw new RuntimeException('FILING_BUNDLE_INSERT_FAILED');
        }

        return $id;
    }

    public function commitFiling(
        string $tenantId,
        string $caseId,
        string $legalActionId,
        string $actorId
    ): array {

        $startedTx = false;

        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $startedTx = true;
        }

        try {

            // ------------------------------------------------------------
            // 0) Case-level advisory lock
            // ------------------------------------------------------------
            $this->acquireCaseLock($tenantId, $caseId);

            // ------------------------------------------------------------
            // 1) Lock lifecycle row
            // ------------------------------------------------------------
            $la = $this->lockLegalActionRow($tenantId, $legalActionId);

            if ($la === null) {
                throw new RuntimeException('LEGAL_ACTION_NOT_FOUND');
            }

            if ((string)$la['case_id'] !== $caseId) {
                throw new RuntimeException('LEGAL_ACTION_CASE_MISMATCH');
            }

            $status = (string)$la['status'];

            // ------------------------------------------------------------
            // 2) Lock case row + freeze state
            // ------------------------------------------------------------
            $caseSt = $this->db->prepare("
                SELECT filing_committed_at, case_event_cursor
                FROM cases
                WHERE tenant_id = :tenant
                AND id = :case_id
                FOR UPDATE
            ");

            $caseSt->execute([
                ':tenant'  => $tenantId,
                ':case_id' => $caseId,
            ]);

            $caseRow = $caseSt->fetch(PDO::FETCH_ASSOC);

            if (!$caseRow) {
                throw new RuntimeException('CASE_NOT_FOUND');
            }

            $caseAlreadyFrozen = !empty($caseRow['filing_committed_at']);

            // ------------------------------------------------------------
            // Idempotent recovery
            // ------------------------------------------------------------
            if ($status === 'prepared_for_filing' || $caseAlreadyFrozen) {

                if ($startedTx) {
                    $this->db->commit();
                }

                $existing = $this->loadCommittedBundle($tenantId, $caseId, $legalActionId);

                return [
                    'case_id'         => $caseId,
                    'legal_action_id' => $legalActionId,
                    'status'          => 'prepared_for_filing',
                    'filing_bundle'   => $existing,
                    'idempotent'      => true,
                ];
            }

            if (!in_array($status, ['initiated','prepared'], true)) {
                throw new RuntimeException('LEGAL_ACTION_STATUS_INVALID');
            }

            // ------------------------------------------------------------
            // 3) Lock prepared bundle
            // ------------------------------------------------------------
            $bundle = $this->lockPreparedBundleRow($tenantId, $caseId, $legalActionId);

            if ($bundle === null) {
                throw new RuntimeException('PREPARED_BUNDLE_NOT_FOUND');
            }

            if ((string)$bundle['freeze_level'] !== 'prepared') {
                throw new RuntimeException('BUNDLE_NOT_PREPARED');
            }

            if (!empty($bundle['frozen'])) {
                throw new RuntimeException('BUNDLE_ALREADY_FROZEN');
            }

            $bundleId     = (string)$bundle['id'];
            $intentHash   = (string)$bundle['intent_hash'];
            $storedHash   = (string)$bundle['bundle_hash'];
            $bundleCursor = isset($bundle['event_cursor']) ? (int)$bundle['event_cursor'] : null;

            // ------------------------------------------------------------
            // 4) Decode canonical inputs
            // ------------------------------------------------------------
            $structured = json_decode((string)$bundle['structured_artifacts_json'], true);
            $exhibit    = json_decode((string)$bundle['exhibit_linkage_c14n_json'], true);
            $versions   = json_decode((string)$bundle['version_hashes_c14n_json'], true);

            if (!is_array($structured) || !is_array($exhibit) || !is_array($versions)) {
                throw new RuntimeException('BUNDLE_CORRUPT_JSON');
            }

            // ------------------------------------------------------------
            // 5) Verify exhibit linkage recomputation
            // ------------------------------------------------------------
            $st = $this->db->prepare("
                SELECT compute_exhibit_linkage_c14n_json(
                    :tenant,
                    :case_id,
                    :legal_action_id
                )
            ");

            $st->execute([
                ':tenant'          => $tenantId,
                ':case_id'         => $caseId,
                ':legal_action_id' => $legalActionId,
            ]);

            $dbExhibit = $st->fetchColumn();

            if ($dbExhibit === false) {
                throw new RuntimeException('EXHIBIT_LINKAGE_RECOMPUTE_FAILED');
            }

            $dbExhibitDecoded = json_decode((string)$dbExhibit, true);

            if (!is_array($dbExhibitDecoded)) {
                throw new RuntimeException('EXHIBIT_LINKAGE_CORRUPT');
            }

            if ($dbExhibitDecoded !== $exhibit) {
                throw new RuntimeException('EXHIBIT_LINKAGE_MISMATCH');
            }

            // ------------------------------------------------------------
            // 6) Build immutable exhibit manifest
            // ------------------------------------------------------------
            $manifestBuilder = new ExhibitManifestBuilder($this->db);

            $manifest = $manifestBuilder->build(
                tenantId: $tenantId,
                caseId: $caseId,
                exhibitLinkage: $exhibit
            );

            $manifestJson = CanonicalJsonService::encode($manifest);

            // Persist manifest into prepared bundle before freeze
            $updateManifest = $this->db->prepare("
                UPDATE filing_bundles
                SET exhibit_manifest_c14n_json = CAST(:manifest AS jsonb)
                WHERE tenant_id = :tenant
                AND id = :bundle_id
            ");

            $updateManifest->execute([
                ':tenant'    => $tenantId,
                ':bundle_id' => $bundleId,
                ':manifest'  => $manifestJson,
            ]);

            // ------------------------------------------------------------
            // 7) Recompute canonical bundle hash INCLUDING manifest
            // ------------------------------------------------------------
            $structuredJson = json_encode($structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $exhibitJson    = json_encode($exhibit, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $versionsJson   = json_encode($versions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (!is_string($structuredJson) || !is_string($exhibitJson) || !is_string($versionsJson)) {
                throw new RuntimeException('BUNDLE_JSON_ENCODE_FAILED');
            }

            $st = $this->db->prepare("
                SELECT compute_bundle_hash(
                    :intent_hash,
                    CAST(:structured AS jsonb),
                    CAST(:exhibit AS jsonb),
                    CAST(:manifest AS jsonb),
                    CAST(:versions AS jsonb)
                )
            ");

            $st->execute([
                ':intent_hash' => $intentHash,
                ':structured'  => $structuredJson,
                ':exhibit'     => $exhibitJson,
                ':manifest'    => $manifestJson,
                ':versions'    => $versionsJson,
            ]);

            $newHash = $st->fetchColumn();

            if (!is_string($newHash) || !hash_equals($storedHash, $newHash)) {
                throw new RuntimeException('BUNDLE_HASH_MISMATCH');
            }

            // ------------------------------------------------------------
            // 8) Verify timeline snapshot integrity
            // ------------------------------------------------------------
            $cursorSt = $this->db->prepare("
                SELECT COALESCE(MAX(case_sequence),0)
                FROM case_events
                WHERE tenant_id = :tenant
                AND case_id = :case_id
            ");

            $cursorSt->execute([
                ':tenant'  => $tenantId,
                ':case_id' => $caseId,
            ]);

            $currentCursor = (int)$cursorSt->fetchColumn();

            if ($currentCursor !== $bundleCursor && $currentCursor !== ($bundleCursor + 1)) {
                throw new RuntimeException('CASE_EVENT_TIMELINE_CHANGED');
            }

            // ------------------------------------------------------------
            // 9) Atomic freeze
            // ------------------------------------------------------------
            $freezeCase = $this->db->prepare("
                UPDATE cases c
                SET
                    filing_committed_at = NOW(),
                    case_event_cursor   = :freeze_cursor
                WHERE c.tenant_id = :tenant
                AND c.id = :case_id
                AND c.filing_committed_at IS NULL
                AND EXISTS (
                    SELECT 1
                    FROM filing_bundles fb
                    WHERE fb.tenant_id = c.tenant_id
                        AND fb.id = :bundle_id
                        AND fb.case_id = c.id
                        AND fb.legal_action_id = :legal_action_id
                        AND fb.freeze_level = 'prepared'
                        AND fb.frozen = false
                        AND fb.event_cursor = :bundle_event_cursor
                )
            ");

            $freezeCase->execute([
                ':tenant'              => $tenantId,
                ':case_id'             => $caseId,
                ':bundle_id'           => $bundleId,
                ':legal_action_id'     => $legalActionId,
                ':bundle_event_cursor' => $bundleCursor,
                ':freeze_cursor'       => $currentCursor,
            ]);

            if ($freezeCase->rowCount() !== 1) {
                throw new RuntimeException('FILING_FREEZE_CONFLICT');
            }

            // ------------------------------------------------------------
            // 10) Mark bundle committed
            // ------------------------------------------------------------
            $st = $this->db->prepare("
                UPDATE filing_bundles
                SET
                    frozen = true,
                    freeze_level = 'committed',
                    committed_at = NOW(),
                    committed_by_actor_id = :actor
                WHERE tenant_id = :tenant
                AND id = :id
                AND freeze_level = 'prepared'
                AND frozen = false
            ");

            $st->execute([
                ':tenant' => $tenantId,
                ':actor'  => $actorId,
                ':id'     => $bundleId,
            ]);

            if ($st->rowCount() !== 1) {
                throw new RuntimeException('BUNDLE_COMMIT_UPDATE_FAILED');
            }

            // ------------------------------------------------------------
            // 11) Transition lifecycle
            // ------------------------------------------------------------
            $updated = $this->transitionLegalActionToPreparedForFiling(
                tenantId: $tenantId,
                legalActionId: $legalActionId,
                intentHash: $intentHash
            );

            if ($updated !== true) {
                throw new RuntimeException('LEGAL_ACTION_TRANSITION_FAILED');
            }

            // ------------------------------------------------------------
            // 12) Emit audit event
            // ------------------------------------------------------------
            try {
                $this->emitCommitAuditEvent(
                    tenantId: $tenantId,
                    caseId: $caseId,
                    legalActionId: $legalActionId,
                    actorId: $actorId,
                    bundleId: $bundleId,
                    bundleHash: $storedHash
                );
            } catch (\Throwable $auditErr) {
                error_log('COMMIT_AUDIT_FAILED: ' . $auditErr->getMessage());
            }

            if ($startedTx) {
                $this->db->commit();
            }

            $committed = $this->loadCommittedBundle($tenantId, $caseId, $legalActionId);

            return [
                'case_id'         => $caseId,
                'legal_action_id' => $legalActionId,
                'status'          => 'prepared_for_filing',
                'bundle_hash'     => $storedHash,
                'event_cursor'    => $currentCursor,
                'filing_bundle'   => $committed,
                'idempotent'      => false,
            ];

        } catch (\Throwable $e) {

            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if ($e instanceof RuntimeException) {
                throw $e;
            }

            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    private function transitionLegalActionToPreparedForFiling(
        string $tenantId,
        string $legalActionId,
        string $intentHash
    ): bool {

        if ($intentHash === '') {
            throw new RuntimeException('INTENT_HASH_REQUIRED_FOR_TRANSITION');
        }

        if (!preg_match('/^[a-f0-9]{64}$/', $intentHash)) {
            throw new RuntimeException('INTENT_HASH_INVALID_FORMAT');
        }

        $st = $this->db->prepare("
            UPDATE legal_actions
            SET
                status = 'prepared_for_filing',
                intent_hash = :intent_hash
            WHERE tenant_id = :tenant
            AND id = :id
            AND status = 'initiated'
        ");

        $st->execute([
            ':tenant'      => $tenantId,
            ':id'          => $legalActionId,
            ':intent_hash' => $intentHash,
        ]);

        return $st->rowCount() === 1;
    }

    private function hashCanonicalJson(array $payload): string
    {
        $canonical = $this->stableJsonEncode($payload);
        return hash('sha256', $canonical);
    }

    private function stableJsonEncode(mixed $v): string
    {
        $normalized = $this->ksortRecursive($v);
        return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function ksortRecursive(mixed $v): mixed
    {
        if (is_array($v)) {
            $isList = array_keys($v) === range(0, count($v) - 1);
            if ($isList) {
                return array_map(fn($x) => $this->ksortRecursive($x), $v);
            }
            ksort($v);
            foreach ($v as $k => $val) {
                $v[$k] = $this->ksortRecursive($val);
            }
        }
        return $v;
    }

    private function isUniqueViolation(\Throwable $e): bool
    {
        // PostgreSQL unique_violation SQLSTATE = 23505
        $cur = $e;

        while ($cur !== null) {

            if ($cur instanceof \PDOException) {

                // SQLSTATE is typically in ->getCode()
                $sqlState = (string) $cur->getCode();

                if ($sqlState === '23505') {
                    return true;
                }

                // In some PDO drivers, SQLSTATE may be in errorInfo[0]
                if (property_exists($cur, 'errorInfo') && is_array($cur->errorInfo)) {
                    $errorInfo = $cur->errorInfo;

                    if (isset($errorInfo[0]) && $errorInfo[0] === '23505') {
                        return true;
                    }
                }
            }

            $cur = $cur->getPrevious();
        }

        return false;
    }

    private function safeRollback(): void
    {
        try {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
        } catch (\Throwable) {
            // swallow rollback failures to avoid masking root exception
        }
    }

    private function loadExhibitSnapshot(
        string $tenantId,
        string $caseId,
        string $legalActionId
    ): array {

        $st = $this->db->prepare("
            SELECT
                artifact_type,
                exhibit_key,
                exhibit_label,
                title,
                source_case_document_id,
                source_version_id,
                content_hash,
                required,
                sort_order
            FROM artifact_exhibit_links
            WHERE tenant_id = :tenant
            AND case_id = :case_id
            AND legal_action_id = :laid
            ORDER BY
                artifact_type ASC,
                required DESC,
                exhibit_key ASC,
                sort_order ASC
        ");

        $st->execute([
            ':tenant'   => $tenantId,
            ':case_id'  => $caseId,
            ':laid'     => $legalActionId,
        ]);

        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return $rows ?: [];
    }

    private function uniqueVersionHashes(array $rows): array
    {
        $map = [];

        foreach ($rows as $r) {
            $key = $r['case_document_id'] . ':' . $r['version_id'];
            $map[$key] = $r;
        }

        $out = array_values($map);

        usort($out, function ($a, $b) {
            $cmp = strcmp($a['case_document_id'], $b['case_document_id']);
            if ($cmp !== 0) return $cmp;
            return strcmp($a['version_id'], $b['version_id']);
        });

        return $out;
    }

    private function loadPreparedBundle(
        string $tenantId,
        string $caseId,
        string $legalActionId
    ): ?array {
        $st = $this->db->prepare("
            SELECT *
            FROM filing_bundles
            WHERE tenant_id = :tenant
            AND case_id = :case_id
            AND legal_action_id = :laid
            AND freeze_level = 'prepared'
            AND frozen = false
            LIMIT 1
        ");

        $st->execute([
            ':tenant'  => $tenantId,
            ':case_id' => $caseId,
            ':laid'    => $legalActionId,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function loadCommittedBundle(
        string $tenantId,
        string $caseId,
        string $legalActionId
    ): ?array {
        $st = $this->db->prepare("
            SELECT *
            FROM filing_bundles
            WHERE tenant_id = :tenant
            AND case_id = :case_id
            AND legal_action_id = :laid
            AND freeze_level = 'committed'
            AND frozen = true
            LIMIT 1
        ");

        $st->execute([
            ':tenant'  => $tenantId,
            ':case_id' => $caseId,
            ':laid'    => $legalActionId,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function lockPreparedBundleRow(
        string $tenantId,
        string $caseId,
        string $legalActionId
    ): ?array {

        $st = $this->db->prepare("
            SELECT *
            FROM filing_bundles
            WHERE tenant_id = :tenant
            AND case_id = :case_id
            AND legal_action_id = :laid
            AND freeze_level = 'prepared'
            AND frozen = false
            FOR UPDATE
            LIMIT 1
        ");

        $st->execute([
            ':tenant'  => $tenantId,
            ':case_id' => $caseId,
            ':laid'    => $legalActionId,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function extractIntentHashFromArtifacts(array $structuredArtifacts): ?string
    {
        foreach ($structuredArtifacts as $a) {
            if (!is_array($a)) continue;
            $meta = $a['metadata'] ?? null;
            if (is_array($meta) && isset($meta['intent_hash']) && is_string($meta['intent_hash']) && $meta['intent_hash'] !== '') {
                return $meta['intent_hash'];
            }
        }
        return null;
    }

    private function emitCommitAuditEvent(
        string $tenantId,
        string $caseId,
        string $legalActionId,
        string $actorId,
        string $bundleId,
        string $bundleHash
    ): void {

        $payload = json_encode([
            'case_id'         => $caseId,
            'legal_action_id' => $legalActionId,
            'bundle_id'       => $bundleId,
            'bundle_hash'     => $bundleHash,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($payload)) {
            throw new RuntimeException('AUDIT_PAYLOAD_JSON_ENCODE_FAILED');
        }

        $st = $this->db->prepare("
            INSERT INTO audit_events (
                tenant_id,
                event_at,
                actor_id,
                entity_type,
                entity_id,
                operation,
                summary,
                diff_payload
            )
            VALUES (
                :tenant,
                now(),
                :actor,
                'filing_bundle',
                :entity_id,
                'committed',
                :summary,
                :payload
            )
        ");

        $st->execute([
            ':tenant'    => $tenantId,
            ':actor'     => $actorId,
            ':entity_id' => $bundleId,
            ':summary'   => 'Filing bundle committed',
            ':payload'   => $payload,
        ]);
    }

    private function emitAuditEvent(
        string $tenantId,
        string $caseId,
        string $legalActionId,
        string $actorId,
        string $bundleId,
        string $intentHash,
        int $eventCursor
    ): void {

        $this->caseEvents->emitEvent(
            'filing_bundle_prepared',
            [
                'legal_action_id' => $legalActionId,
                'bundle_id'       => $bundleId,
                'intent_hash'     => $intentHash,
                'event_cursor'    => $eventCursor
            ],
            $tenantId,
            $caseId,
            $actorId
        );       
            if ($eventCursor < 0) {
            throw new RuntimeException('INVALID_EVENT_CURSOR');
        }
    }

    private function acquireCaseLock(string $tenantId, string $caseId): void
    {
        $st = $this->db->prepare("
            SELECT pg_advisory_xact_lock(
                ('x' || substr(replace(:tenant_id,'-',''),1,8))::bit(32)::int,
                ('x' || substr(replace(:case_id,'-',''),1,8))::bit(32)::int
            )
        ");

        $st->execute([
            ':tenant_id' => $tenantId,
            ':case_id'   => $caseId,
        ]);
    }

}