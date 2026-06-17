<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Phase 16.9 — Artifact Filing Readiness
 *
 * This service evaluates whether a fully assembled,
 * structurally-valid artifact bundle is substantively
 * ready to be filed.
 *
 * It MUST:
 *  - Be deterministic
 *  - Be idempotent
 *  - Have no side effects
 *  - Perform no DB reads
 *  - Not mutate intent or artifacts
 */
final class ArtifactFilingReadinessService
{
    /**
     * Callable structural validator.
     *
     * Signature:
     *   fn(array $intent, array $artifacts, string $actionType): array
     *
     * Must return Phase 16.8 structural validation payload.
     */
    private $structuralValidator;

    /**
     * @var array<string, list<callable>>
     */
    private array $rulesByActionType = [];

    public function __construct(callable $structuralValidator)
    {
        $this->structuralValidator = $structuralValidator;

        $this->rulesByActionType = [
            'az_forcible_detainer' => [
                [$this, 'rulePartyRequirements'],
                [$this, 'ruleCaptionPresent'],
                [$this, 'rulePropertyStreetRequired'],
                [$this, 'ruleAtLeastOneExhibit'],
                [$this, 'ruleRequiredExhibitsSatisfied'],
                [$this, 'ruleDemandMetadata'],
                [$this, 'ruleComplaintRequiredBlocks'],
            ],
        ];
    }

    /**
     * Evaluate artifact filing readiness.
     *
     * Returns:
     * [
     *   ready_for_filing => bool,
     *   blocking_reasons => string[],
     *   warnings => string[],
     *   structural_validation => array
     * ]
     */
    public function evaluate(
        array $intent,
        array $artifacts,
        string $actionType
    ): array {

        $structural = ($this->structuralValidator)(
            $intent,
            $artifacts,
            $actionType
        );

        $blocking = [];
        $warnings = [];

        if (($structural['structural_status'] ?? '') !== 'valid') {
            $blocking[] = 'STRUCTURAL_INVALID';
        }

        $rules = $this->rulesByActionType[$actionType] ?? [];

        foreach ($rules as $rule) {
            $result = $rule($intent, $artifacts);

            foreach ($result['blocking_reasons'] ?? [] as $b) {
                $blocking[] = $b;
            }

            foreach ($result['warnings'] ?? [] as $w) {
                $warnings[] = $w;
            }
        }

        $blocking = $this->uniqueStable($blocking);
        $warnings = $this->uniqueStable($warnings);

        return [
            'ready_for_filing' => count($blocking) === 0,
            'blocking_reasons' => $blocking,
            'warnings' => $warnings,
            'structural_validation' => $structural,
        ];
    }

    /* ============================================================
     * AZ FD RULES
     * ============================================================ */

    private function rulePartyRequirements(array $intent, array $artifacts): array
    {
        $blocking = [];

        $plaintiffs = $intent['plaintiffs'] ?? [];
        $defendants = $intent['defendants'] ?? [];

        if (!is_array($plaintiffs) || count($plaintiffs) < 1) {
            $blocking[] = 'NO_PLAINTIFF_PRESENT';
        }

        if (!is_array($defendants) || count($defendants) < 1) {
            $blocking[] = 'NO_DEFENDANT_PRESENT';
        }

        return $this->result($blocking);
    }

    private function ruleCaptionPresent(array $intent, array $artifacts): array
    {
        $caption = $intent['caption']['formatted'] ?? null;

        if (!is_string($caption) || trim($caption) === '') {
            return $this->result(['CAPTION_REQUIRED']);
        }

        return $this->result();
    }

    private function rulePropertyStreetRequired(array $intent, array $artifacts): array
    {
        $line1 = $intent['property']['address']['line1'] ?? null;

        if (!is_string($line1) || trim($line1) === '') {
            return $this->result(['PROPERTY_ADDRESS_REQUIRED']);
        }

        return $this->result();
    }

    private function ruleAtLeastOneExhibit(array $intent, array $artifacts): array
    {
        $exhibits = $this->extractExhibits($artifacts);

        error_log("READINESS ARTIFACT KEYS:");
        error_log(json_encode(array_keys($artifacts)));        

        if (count($exhibits) < 1) {
            return $this->result(['AT_LEAST_ONE_EXHIBIT_REQUIRED']);
        }

        return $this->result();
    }

    private function ruleRequiredExhibitsSatisfied(array $intent, array $artifacts): array
    {
        $exhibits = $this->extractExhibits($artifacts);

        foreach ($exhibits as $ex) {
            if (!is_array($ex)) {
                continue;
            }

            if (!($ex['required'] ?? false)) {
                continue;
            }

            $hasRef = false;

            foreach ([
                'source_case_document_id',
                'source_version_id',
                'content_hash'
            ] as $key) {
                if (!empty($ex[$key])) {
                    $hasRef = true;
                    break;
                }
            }

            if (!$hasRef) {
                return $this->result(['REQUIRED_EXHIBIT_MISSING']);
            }
        }

        return $this->result();
    }

    private function ruleDemandMetadata(array $intent, array $artifacts): array
    {
        $blocking = [];

        $method = $intent['demand']['method']
            ?? $intent['demand']['delivery_method']
            ?? null;

        $date = $intent['demand']['date']
            ?? $intent['demand']['demand_date']
            ?? null;

        $basis = $intent['demand']['statutory_basis']
            ?? $intent['demand']['basis']
            ?? null;

        if (empty($method)) {
            $blocking[] = 'DEMAND_METHOD_REQUIRED';
        }

        if (empty($date)) {
            $blocking[] = 'DEMAND_DATE_REQUIRED';
        }

        if (empty($basis)) {
            $blocking[] = 'DEMAND_STATUTORY_BASIS_REQUIRED';
        }

        return $this->result($blocking);
    }

    private function ruleComplaintRequiredBlocks(array $intent, array $artifacts): array
    {
        $complaint = $this->findArtifact($artifacts, ['complaint']);

        if ($complaint === null) {
            return $this->result(['COMPLAINT_ARTIFACT_REQUIRED']);
        }

        $sections = $complaint['content']['sections'] ?? null;

        if (!is_array($sections)) {
            return $this->result(['COMPLAINT_STRUCTURE_INVALID']);
        }

        $blocking = [];

        if (!isset($sections['relief']) || !is_array($sections['relief'])) {
            $blocking[] = 'RELIEF_BLOCK_REQUIRED';
        }

        if (!isset($sections['signature']) || !is_array($sections['signature'])) {
            $blocking[] = 'SIGNATURE_BLOCK_REQUIRED';
        }

        return $this->result($blocking);
    }

    /* ============================================================
     * Helpers
     * ============================================================ */

    private function result(array $blocking = [], array $warnings = []): array
    {
        return [
            'blocking_reasons' => $blocking,
            'warnings' => $warnings,
        ];
    }

    private function uniqueStable(array $input): array
    {
        $seen = [];
        $out = [];

        foreach ($input as $v) {
            if (!is_string($v) || $v === '') {
                continue;
            }

            if (!isset($seen[$v])) {
                $seen[$v] = true;
                $out[] = $v;
            }
        }

        return $out;
    }

    private function extractExhibits(array $artifacts): array
    {
        foreach ($artifacts as $artifact) {

            if (!is_array($artifact)) {
                continue;
            }

            if (
                isset($artifact['content']['sections']['exhibits']) &&
                is_array($artifact['content']['sections']['exhibits'])
            ) {
                return $artifact['content']['sections']['exhibits'];
            }
        }

        return [];
    }

    private function findArtifact(array $artifacts, array $types): ?array
    {
        $list = $artifacts['artifacts'] ?? $artifacts;

        if (!is_array($list)) {
            return null;
        }

        foreach ($list as $a) {
            if (!is_array($a)) {
                continue;
            }

            $type = strtolower((string)($a['artifact_type']
                ?? $a['type']
                ?? $a['key']
                ?? ''));

            if (in_array($type, $types, true)) {
                return $a;
            }
        }

        return null;
    }
}