<?php
declare(strict_types=1);

namespace App\Services\Filing;

use RuntimeException;

/**
 * Phase 20.5 — Filing Packet Manifest Service
 *
 * Responsibilities
 * ----------------
 * • Canonical packet ordering
 * • Court-type packet policy enforcement
 * • Packet manifest construction
 * • Packet manifest hashing
 * • Embedded proof payload generation
 *
 * Deterministic Inputs
 * --------------------
 * tenant_id
 * case_id
 * legal_action_id
 * bundle_id
 * bundle_hash
 * court_type
 * generated document metadata
 *
 * Deterministic Output
 * --------------------
 * canonical manifest
 * packet_manifest_hash
 * embedded proof payload
 *
 * No database access.
 * No file IO.
 */
final class FilingPacketManifestService
{
    public const PROOF_TYPE = 'filing_packet_manifest_v1';

    /**
     * Canonical packet order.
     *
     * This order must NEVER drift.
     */
    private const CANONICAL_PACKET_ORDER = [
        'summons',
        'complaint',
        'exhibit_packet',
        'certificate_compulsory_arbitration',
    ];

    /**
     * Court packet policy.
     */
    private const PACKET_POLICY = [

        'justice' => [
            'required' => [
                'summons',
                'complaint',
                'exhibit_packet',
            ],
            'optional' => [],
            'forbidden' => [
                'certificate_compulsory_arbitration',
            ],
        ],

        'superior' => [
            'required' => [
                'summons',
                'complaint',
                'exhibit_packet',
                'certificate_compulsory_arbitration',
            ],
            'optional' => [],
            'forbidden' => [],
        ],

    ];

    /**
     * Build the canonical packet manifest.
     */
    public function buildPacketManifest(
        string $tenantId,
        string $caseId,
        string $legalActionId,
        string $bundleId,
        string $bundleHash,
        string $courtType,
        array $documents
    ): array {

        $courtType = strtolower($courtType);

        if (!isset(self::PACKET_POLICY[$courtType])) {
            throw new RuntimeException('INVALID_PACKET_COURT_TYPE');
        }

        $policy = self::PACKET_POLICY[$courtType];

        /*
         * Normalize input documents by type
         */
        $docMap = [];

        foreach ($documents as $doc) {

            if (!isset($doc['document_type'])) {
                throw new RuntimeException('INVALID_PACKET_SOURCE_DOCUMENT');
            }

            $type = (string)$doc['document_type'];

            if (isset($docMap[$type])) {
                throw new RuntimeException("DUPLICATE_PACKET_DOCUMENT:{$type}");
            }

            if (!isset($doc['document_id'], $doc['file_hash'])) {
                throw new RuntimeException("INVALID_PACKET_SOURCE_HASH:{$type}");
            }

            $docMap[$type] = [
                'document_type' => $type,
                'document_id'   => (string)$doc['document_id'],
                'file_hash'     => (string)$doc['file_hash'],
            ];
        }

        /*
         * Validate required documents
         */
        foreach ($policy['required'] as $required) {
            if (!isset($docMap[$required])) {
                throw new RuntimeException("MISSING_REQUIRED_PACKET_DOCUMENT:{$required}");
            }
        }

        /*
         * Validate forbidden documents
         */
        foreach ($policy['forbidden'] as $forbidden) {
            if (isset($docMap[$forbidden])) {
                throw new RuntimeException("FORBIDDEN_PACKET_DOCUMENT:{$forbidden}");
            }
        }

        /*
         * Construct canonical ordered document list
         */
        $orderedDocuments = [];
        $documentOrder = [];

        foreach (self::CANONICAL_PACKET_ORDER as $type) {

            if (!isset($docMap[$type])) {
                continue;
            }

            $orderedDocuments[] = $docMap[$type];
            $documentOrder[] = $type;
        }

        /*
         * Build manifest
         */
        return [

            'bundle_id' => $bundleId,
            'bundle_hash' => $bundleHash,

            'tenant_id' => $tenantId,
            'case_id' => $caseId,
            'legal_action_id' => $legalActionId,

            'court_type' => $courtType,

            'document_order' => $documentOrder,

            'documents' => $orderedDocuments,
        ];
    }

    /**
     * Compute packet manifest hash.
     *
     * Deterministic hash over canonical JSON.
     */
    public function computePacketManifestHash(array $manifest): string
    {
        $normalized = $this->canonicalize($manifest);

        $json = json_encode(
            $normalized,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new RuntimeException('PACKET_MANIFEST_HASH_COMPUTE_FAILED');
        }

        return hash('sha256', $json);
    }

    /**
     * Build embedded proof payload.
     */
    public function buildEmbeddedProof(
        array $manifest,
        string $packetManifestHash
    ): array {

        return [

            'proof_type' => self::PROOF_TYPE,

            'bundle_hash' => $manifest['bundle_hash'],

            'packet_manifest_hash' => $packetManifestHash,

            'document_order' => $manifest['document_order'],

            'documents' => $manifest['documents'],
        ];
    }

    /**
     * Canonical JSON normalization.
     *
     * Ensures deterministic hashing.
     */
    private function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {

            if ($this->isAssoc($value)) {

                ksort($value);

                foreach ($value as $k => $v) {
                    $value[$k] = $this->canonicalize($v);
                }

                return $value;
            }

            $out = [];

            foreach ($value as $v) {
                $out[] = $this->canonicalize($v);
            }

            return $out;
        }

        return $value;
    }

    /**
     * Detect associative array.
     */
    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}