<?php
declare(strict_types=1);

namespace App\Services\Validation;

final class ComplaintValidator
{
    public function validate(array $artifact): array
    {
        $errors   = [];
        $warnings = [];

        /* ============================================================
         * 1) Artifact Envelope
         * ============================================================ */

        if (($artifact['artifact_type'] ?? null) !== 'complaint') {
            return $this->result(['INVALID_ARTIFACT_TYPE'], []);
        }

        if (($artifact['format'] ?? null) !== 'structured') {
            $errors[] = 'INVALID_ARTIFACT_FORMAT';
        }

        if (($artifact['preview_only'] ?? null) !== true) {
            $errors[] = 'PREVIEW_FLAG_REQUIRED';
        }

        if (!isset($artifact['artifact_schema_version'])) {
            $errors[] = 'MISSING_SCHEMA_VERSION';
        }

        $metadata = $artifact['metadata'] ?? null;
        if (!is_array($metadata)) {
            return $this->result(array_merge($errors, ['MISSING_METADATA_BLOCK']), $warnings);
        }

        $actionType = $metadata['action_type'] ?? null;
        if (!is_string($actionType) || $actionType === '') {
            $errors[] = 'MISSING_ACTION_TYPE';
        }

        if (empty($metadata['intent_hash'])) {
            $errors[] = 'MISSING_INTENT_HASH';
        }

        if (empty($metadata['lifecycle_stage'])) {
            $errors[] = 'MISSING_LIFECYCLE_STAGE';
        }

        /* ============================================================
         * 2) Policy Lookup
         * ============================================================ */

        $policy = ArtifactPolicyRegistry::getActionPolicy($actionType ?? '');
        if (!$policy) {
            return $this->result(['UNKNOWN_ACTION_POLICY'], []);
        }

        /* ============================================================
         * 3) Registry-driven whitelists + schema/lifecycle enforcement
         * ============================================================ */

        // Envelope whitelist
        $envelopeWhitelist = ArtifactPolicyRegistry::getEnvelopeWhitelist($actionType ?? '');
        if (is_array($envelopeWhitelist)) {
            $this->validateAllowedKeys($artifact, $envelopeWhitelist, $warnings, 'ENVELOPE');
        }

        // Metadata whitelist
        $metadataWhitelist = ArtifactPolicyRegistry::getMetadataWhitelist($actionType ?? '');
        if (is_array($metadataWhitelist)) {
            $this->validateAllowedKeys($metadata, $metadataWhitelist, $warnings, 'METADATA');
        }

        // Schema version enforcement
        $expectedSchema = ArtifactPolicyRegistry::getSchemaVersion($actionType ?? '');
        if (($artifact['artifact_schema_version'] ?? null) !== $expectedSchema) {
            $errors[] = 'INVALID_SCHEMA_VERSION';
        }

        // Lifecycle stage enforcement
        $rules         = ArtifactPolicyRegistry::getArtifactRules($actionType ?? '', 'complaint');
        $expectedStage = $rules['required_lifecycle_stage'] ?? null;
        $actualStage   = $metadata['lifecycle_stage'] ?? null;

        if ($expectedStage !== null && $actualStage !== $expectedStage) {
            $errors[] = 'INVALID_LIFECYCLE_STAGE';
        }

        if (!empty($errors)) {
            return $this->result($errors, $warnings);
        }

        /* ============================================================
         * 4) Content Block
         * ============================================================ */

        $content = $artifact['content'] ?? null;
        if (!is_array($content)) {
            return $this->result(['MISSING_CONTENT_BLOCK'], []);
        }

        // Content top-level whitelist (policy-driven)
        $contentWhitelist = ArtifactPolicyRegistry::getContentTopLevelWhitelist(
            $actionType ?? '',
            'complaint'
        );

        if (is_array($contentWhitelist)) {
            $this->validateAllowedKeys($content, $contentWhitelist, $warnings, 'CONTENT');
        }

        if (!isset($content['document_structure_version'])) {
            $errors[] = 'DOCUMENT_STRUCTURE_VERSION_REQUIRED';
        }

        /* ============================================================
         * 5) Court Heading
         * ============================================================ */

        $this->requireArray($content, 'court_heading', $errors);
        $this->validateCourtHeading($content['court_heading'] ?? null, $errors);

        /* ============================================================
         * 6) Caption (policy-driven whitelist)
         * ============================================================ */

        $this->requireArray($content, 'caption', $errors);

        if (isset($content['caption']) && is_array($content['caption'])) {
            $this->validateCaptionWithPolicy(
                $content['caption'],
                (string)$actionType,
                $errors,
                $warnings
            );
        }

        /* ============================================================
         * 7) Sections (Phase 17 v2 complaint schema)
         * ============================================================ */

        $this->requireArray($content, 'sections', $errors);

        $sections = $content['sections'] ?? null;
        if (!is_array($sections)) {
            return $this->result($errors, $warnings);
        }

        // Required section existence (policy-driven)
        $requiredSections = $rules['required_sections'] ?? [];
        foreach ($requiredSections as $sectionKey) {
            if (!array_key_exists($sectionKey, $sections)) {
                $errors[] = 'MISSING_REQUIRED_SECTION_' . strtoupper((string)$sectionKey);
            }
        }

        // Phase 17 v2: Property Address
        if (!isset($sections['property_address']) || !is_array($sections['property_address'])) {
            $errors[] = 'PROPERTY_ADDRESS_REQUIRED';
        } else {
            if (empty($sections['property_address']['street_address'])) {
                $errors[] = 'PROPERTY_ADDRESS_REQUIRED';
            }
        }

        // Phase 17 v2: Demand block required fields
        if (!isset($sections['demand']) || !is_array($sections['demand'])) {
            // Keep this aligned with readiness vocabulary:
            // missing demand block implies the individual required fields are missing.
            $errors[] = 'DEMAND_METHOD_REQUIRED';
            $errors[] = 'DEMAND_DATE_REQUIRED';
            $errors[] = 'DEMAND_STATUTORY_BASIS_REQUIRED';
        } else {
            if (empty($sections['demand']['method'])) {
                $errors[] = 'DEMAND_METHOD_REQUIRED';
            }

            if (empty($sections['demand']['date'])) {
                $errors[] = 'DEMAND_DATE_REQUIRED';
            }

            if (empty($sections['demand']['statutory_basis'])) {
                $errors[] = 'DEMAND_STATUTORY_BASIS_REQUIRED';
            }
        }

        // Phase 17 v2: Relief block
        if (!isset($sections['relief']) || !is_array($sections['relief'])) {
            $errors[] = 'RELIEF_BLOCK_REQUIRED';
        }

        // Phase 17 v2: Signature is under sections.signature
        if (!isset($sections['signature']) || !is_array($sections['signature'])) {
            $errors[] = 'SIGNATURE_BLOCK_REQUIRED';
        } else {
            // Minimal structural requirement for now
            if (!isset($sections['signature']['attorney_name']) || trim((string)$sections['signature']['attorney_name']) === '') {
                $errors[] = 'SIGNATURE_BLOCK_REQUIRED';
            }
        }

        /* ============================================================
         * 8) Exhibits (policy-driven)
         * ============================================================ */

        if (!empty($rules['requires_exhibits'])) {
            $this->validateExhibits($sections, $errors);
        }

        /* ============================================================
         * 9) Eviction Warning (policy-driven)
         * ============================================================ */

        if (!empty($rules['requires_eviction_warning'])) {
            $this->validateEvictionWarningBlock($content, $errors);
        }

        error_log(json_encode($result, JSON_PRETTY_PRINT));

        return $this->result($errors, $warnings);
    }

    /* ============================================================
     * Validators
     * ============================================================ */

    private function validateCourtHeading($block, array &$errors): void
    {
        if (!is_array($block)) {
            $errors[] = 'COURT_HEADING_REQUIRED';
            return;
        }

        foreach (['state','county','court_type','precinct'] as $k) {
            if (!array_key_exists($k, $block)) {
                $errors[] = 'COURT_HEADING_MISSING_' . strtoupper($k);
            }
        }
    }

    /**
     * NOTE: legacy validateCaption() retained for completeness,
     * but Phase 16+ uses validateCaptionWithPolicy().
     */
    private function validateCaption($block, array &$errors): void
    {
        if (!is_array($block)) {
            $errors[] = 'CAPTION_REQUIRED';
            return;
        }

        foreach (['plaintiffs','defendants','title','statutory_reference'] as $k) {
            if (!array_key_exists($k, $block)) {
                $errors[] = 'CAPTION_MISSING_' . strtoupper($k);
            }
        }

        if (!is_array($block['plaintiffs'] ?? null)) {
            $errors[] = 'CAPTION_PLAINTIFFS_INVALID';
        }

        if (!is_array($block['defendants'] ?? null)) {
            $errors[] = 'CAPTION_DEFENDANTS_INVALID';
        }
    }

    private function validateExhibits(array $sections, array &$errors): void
    {
        if (!isset($sections['exhibits']) || !is_array($sections['exhibits'])) {
            $errors[] = 'EXHIBITS_BLOCK_REQUIRED';
            return;
        }

        /**
         * Phase 17:
         * Make exhibit requirement opt-in via env flag.
         * - REQUIRE_EXHIBIT_FOR_FILING=true  => enforce at least one exhibit
         * - absent/false/other              => do not enforce count > 0
         */
        $requireExhibits = false;
        $env = getenv('REQUIRE_EXHIBIT_FOR_FILING');
        if ($env !== false && strtolower(trim((string)$env)) === 'true') {
            $requireExhibits = true;
        }

        if ($requireExhibits && count($sections['exhibits']) === 0) {
            $errors[] = 'AT_LEAST_ONE_EXHIBIT_REQUIRED';
            return;
        }

        $labelsSeen = [];

        foreach ($sections['exhibits'] as $index => $exhibit) {

            if (!is_array($exhibit)) {
                $errors[] = 'EXHIBIT_INVALID_AT_INDEX_' . $index;
                continue;
            }

            $label = $exhibit['exhibit_label'] ?? null;

            if (!is_string($label) || trim($label) === '') {
                $errors[] = 'EXHIBIT_LABEL_REQUIRED_' . $index;
            } else {
                // Avoid mb_* dependency here; uppercasing exhibit labels isn't critical.
                $canon = strtoupper(trim(preg_replace('/\s+/', ' ', $label)));
                if (isset($labelsSeen[$canon])) {
                    $errors[] = 'DUPLICATE_EXHIBIT_LABEL_' . $index;
                }
                $labelsSeen[$canon] = true;
            }

            if (!array_key_exists('source_document_id', $exhibit)) {
                $errors[] = 'EXHIBIT_SOURCE_DOCUMENT_KEY_REQUIRED_' . $index;
            }

            if (!array_key_exists('required', $exhibit) || !is_bool($exhibit['required'])) {
                $errors[] = 'EXHIBIT_REQUIRED_FLAG_INVALID_' . $index;
            }
        }
    }

    private function validateEvictionWarningBlock(array $content, array &$errors): void
    {
        $block = $content['eviction_warning_block'] ?? null;

        if (!is_array($block)) {
            $errors[] = 'MISSING_STATUTORY_EVICTION_WARNING_BLOCK';
            return;
        }

        if (($block['type'] ?? null) !== 'statutory_eviction_warning') {
            $errors[] = 'INVALID_WARNING_BLOCK_TYPE';
        }

        if (($block['placement'] ?? null) !== 'post_caption_pre_allegations') {
            $errors[] = 'INVALID_WARNING_BLOCK_PLACEMENT';
        }

        $format = $block['formatting'] ?? null;

        if (!is_array($format)) {
            $errors[] = 'WARNING_FORMATTING_REQUIRED';
        } else {
            if (($format['bold'] ?? false) !== true) {
                $errors[] = 'WARNING_MUST_BE_BOLD';
            }

            if (($format['underline'] ?? false) !== true) {
                $errors[] = 'WARNING_MUST_BE_UNDERLINED';
            }
        }

        if (empty($block['text'])) {
            $errors[] = 'WARNING_TEXT_REQUIRED';
        }
    }

    /**
     * NOTE: legacy signature validation retained for compatibility,
     * but v2 complaint uses sections.signature.
     */
    private function validateSignatureBlock($block, array &$errors): void
    {
        if (!is_array($block)) {
            $errors[] = 'SIGNATURE_BLOCK_REQUIRED';
            return;
        }

        if (!array_key_exists('attorney_name', $block)) {
            $errors[] = 'SIGNATURE_ATTORNEY_NAME_FIELD_REQUIRED';
        }
    }

    /* ============================================================
     * Helpers
     * ============================================================ */

    private function requireArray(array $content, string $key, array &$errors): void
    {
        if (!array_key_exists($key, $content) || !is_array($content[$key])) {
            $errors[] = 'MISSING_OR_INVALID_' . strtoupper($key);
        }
    }

    private function result(array $errors, array $warnings): array
    {
        return [
            'structural_status'   => empty($errors) ? 'valid' : 'invalid',
            'validation_errors'   => array_values($errors),
            'validation_warnings' => array_values($warnings),
        ];
    }

    private function validateAllowedKeys(array $data, array $allowed, array &$warnings, string $scope): void
    {
        $allowedMap = array_flip($allowed);

        foreach ($data as $key => $_) {
            if (!isset($allowedMap[$key])) {
                $warnings[] = 'UNKNOWN_KEY_' . $scope . '_' . strtoupper((string)$key);
            }
        }
    }

    private function validateCaptionWithPolicy(
        array $caption,
        string $actionType,
        array &$errors,
        array &$warnings
    ): void {

        $policy = ArtifactPolicyRegistry::getCaptionWhitelist($actionType);

        if (!$policy) {
            $errors[] = 'CAPTION_POLICY_NOT_FOUND';
            return;
        }

        $allowedCaptionKeys = $policy['allowed_caption_keys'] ?? [];
        $allowedPartyKeys   = $policy['allowed_party_keys'] ?? [];

        // Validate caption top-level keys
        $this->validateAllowedKeys(
            $caption,
            $allowedCaptionKeys,
            $warnings,
            'CAPTION'
        );

        /*
         * Phase 16 Caption Model:
         *  - plaintiffs: array
         *  - defendants: array
         */

        if (!isset($caption['plaintiffs']) || !is_array($caption['plaintiffs'])) {
            $errors[] = 'CAPTION_PLAINTIFFS_REQUIRED';
        }

        if (!isset($caption['defendants']) || !is_array($caption['defendants'])) {
            $errors[] = 'CAPTION_DEFENDANTS_REQUIRED';
        }

        // Validate plaintiff structure
        foreach ($caption['plaintiffs'] ?? [] as $index => $party) {

            if (!is_array($party)) {
                $errors[] = 'CAPTION_PLAINTIFF_INVALID_' . $index;
                continue;
            }

            $this->validateAllowedKeys(
                $party,
                $allowedPartyKeys,
                $warnings,
                'CAPTION_PARTY'
            );

            if (!isset($party['intent_role'])) {
                $errors[] = 'CAPTION_PLAINTIFF_ROLE_REQUIRED_' . $index;
            }

            if (!isset($party['name'])) {
                $errors[] = 'CAPTION_PLAINTIFF_NAME_REQUIRED_' . $index;
            }
        }

        // Validate defendant structure
        foreach ($caption['defendants'] ?? [] as $index => $party) {

            if (!is_array($party)) {
                $errors[] = 'CAPTION_DEFENDANT_INVALID_' . $index;
                continue;
            }

            $this->validateAllowedKeys(
                $party,
                $allowedPartyKeys,
                $warnings,
                'CAPTION_PARTY'
            );

            if (!isset($party['intent_role'])) {
                $errors[] = 'CAPTION_DEFENDANT_ROLE_REQUIRED_' . $index;
            }

            if (!isset($party['name'])) {
                $errors[] = 'CAPTION_DEFENDANT_NAME_REQUIRED_' . $index;
            }
        }
    }
}