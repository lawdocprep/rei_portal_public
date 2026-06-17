<?php
declare(strict_types=1);

namespace App\Services\Validation;

final class ArbitrationCertificateValidator
{
    public function validate(array $artifact): array
    {
        $errors = [];
        $warnings = [];

        /* ------------------------------------------------------------
         * 1️⃣ Basic Envelope Checks
         * ------------------------------------------------------------ */

        if (($artifact['artifact_type'] ?? null) !== 'certificate_compulsory_arbitration') {
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

        /* ------------------------------------------------------------
         * 2️⃣ Policy Lookup
         * ------------------------------------------------------------ */

        $policy = ArtifactPolicyRegistry::getActionPolicy($actionType ?? '');

        if (!$policy) {
            return $this->result(['UNKNOWN_ACTION_POLICY'], []);
        }

        $expectedSchema = ArtifactPolicyRegistry::getSchemaVersion($actionType);

        if (($artifact['artifact_schema_version'] ?? null) !== $expectedSchema) {
            $errors[] = 'INVALID_SCHEMA_VERSION';
        }

        $rules = ArtifactPolicyRegistry::getArtifactRules(
            $actionType,
            'certificate_compulsory_arbitration'
        );

        $expectedStage = $rules['required_lifecycle_stage'] ?? null;
        $actualStage   = $metadata['lifecycle_stage'] ?? null;

        if ($expectedStage !== null && $actualStage !== $expectedStage) {
            $errors[] = 'INVALID_LIFECYCLE_STAGE';
        }

        /* ------------------------------------------------------------
         * 3️⃣ Registry-Driven Whitelists
         * ------------------------------------------------------------ */

        $envelopeWhitelist = ArtifactPolicyRegistry::getEnvelopeWhitelist($actionType);
        if (is_array($envelopeWhitelist)) {
            $this->validateAllowedKeys(
                $artifact,
                $envelopeWhitelist,
                $warnings,
                'ENVELOPE'
            );
        }

        $metadataWhitelist = ArtifactPolicyRegistry::getMetadataWhitelist($actionType);
        if (is_array($metadataWhitelist)) {
            $this->validateAllowedKeys(
                $metadata,
                $metadataWhitelist,
                $warnings,
                'METADATA'
            );
        }

        if (!empty($errors)) {
            return $this->result($errors, $warnings);
        }

        /* ------------------------------------------------------------
         * 4️⃣ Content Block
         * ------------------------------------------------------------ */

        $content = $artifact['content'] ?? null;

        if (!is_array($content)) {
            return $this->result(['MISSING_CONTENT_BLOCK'], []);
        }

        $contentWhitelist = ArtifactPolicyRegistry::getContentTopLevelWhitelist(
            $actionType,
            'certificate_compulsory_arbitration'
        );

        if (is_array($contentWhitelist)) {
            $this->validateAllowedKeys(
                $content,
                $contentWhitelist,
                $warnings,
                'CONTENT'
            );
        }

        /* ------------------------------------------------------------
         * 5️⃣ Core Structural Validation
         * ------------------------------------------------------------ */

        $this->requireInt($content, 'document_structure_version', $errors);
        $this->requireString($content, 'action_type', $errors);

        if (($content['action_type'] ?? null) !== $metadata['action_type']) {
            $errors[] = 'ACTION_TYPE_MISMATCH_METADATA_VS_CONTENT';
        }

        $this->requireArray($content, 'attorney_block', $errors);
        $this->validateAttorneyBlock($content['attorney_block'] ?? null, $errors);

        $this->requireArray($content, 'court_heading', $errors);
        $this->validateCourtHeading($content['court_heading'] ?? null, $errors);

        $this->requireArray($content, 'caption', $errors);
        $this->validateCaption($content['caption'] ?? null, $errors);

        $this->requireArray($content, 'certificate_body', $errors);
        $this->validateCertificateBody($content['certificate_body'] ?? null, $errors);

        $this->requireArray($content, 'signature_block', $errors);
        $this->validateSignatureBlock($content['signature_block'] ?? null, $errors);

        $this->requireArray($content, 'filing_acknowledgment', $errors);
        $this->validateFilingAcknowledgment($content['filing_acknowledgment'] ?? null, $errors);

        return $this->result($errors, $warnings);
    }

    /* ============================================================
     * Block-Level Structural Validators (unchanged logic)
     * ============================================================ */

    private function validateAttorneyBlock($block, array &$errors): void
    {
        if (!is_array($block)) {
            $errors[] = 'ATTORNEY_BLOCK_REQUIRED';
            return;
        }

        foreach (['attorney_name','bar_number','firm_name','address','phone','email','attorneys_for'] as $k) {
            if (!array_key_exists($k, $block)) {
                $errors[] = 'ATTORNEY_BLOCK_MISSING_' . strtoupper($k);
            }
        }

        if (array_key_exists('attorneys_for', $block) && !is_string($block['attorneys_for'])) {
            $errors[] = 'ATTORNEY_BLOCK_ATTORNEYS_FOR_INVALID';
        }
    }

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

    private function validateCaption($block, array &$errors): void
    {
        if (!is_array($block)) {
            $errors[] = 'CAPTION_REQUIRED';
            return;
        }

        foreach (['plaintiffs','defendants','case_number'] as $k) {
            if (!array_key_exists($k, $block)) {
                $errors[] = 'CAPTION_MISSING_' . strtoupper($k);
            }
        }

        if (array_key_exists('plaintiffs', $block) && !is_array($block['plaintiffs'])) {
            $errors[] = 'CAPTION_PLAINTIFFS_INVALID';
        }

        if (array_key_exists('defendants', $block) && !is_array($block['defendants'])) {
            $errors[] = 'CAPTION_DEFENDANTS_INVALID';
        }
    }

    private function validateCertificateBody($block, array &$errors): void
    {
        if (!is_array($block)) {
            $errors[] = 'CERTIFICATE_BODY_REQUIRED';
            return;
        }

        foreach (['relief_type','subject_to_compulsory_arbitration','rule_reference'] as $k) {
            if (!array_key_exists($k, $block)) {
                $errors[] = 'CERTIFICATE_BODY_MISSING_' . strtoupper($k);
            }
        }

        if (!is_string($block['relief_type'] ?? null)) {
            $errors[] = 'CERTIFICATE_BODY_RELIEF_TYPE_INVALID';
        }

        if (!is_bool($block['subject_to_compulsory_arbitration'] ?? null)) {
            $errors[] = 'CERTIFICATE_BODY_SUBJECT_FLAG_INVALID';
        }

        if (!is_string($block['rule_reference'] ?? null)) {
            $errors[] = 'CERTIFICATE_BODY_RULE_REFERENCE_INVALID';
        }
    }

    private function validateSignatureBlock($block, array &$errors): void
    {
        if (!is_array($block)) {
            $errors[] = 'SIGNATURE_BLOCK_REQUIRED';
            return;
        }

        foreach (['submitted_date','law_firm_name','attorney_name','address','phone'] as $k) {
            if (!array_key_exists($k, $block)) {
                $errors[] = 'SIGNATURE_BLOCK_MISSING_' . strtoupper($k);
            }
        }
    }

    private function validateFilingAcknowledgment($block, array &$errors): void
    {
        if (!is_array($block)) {
            $errors[] = 'FILING_ACKNOWLEDGMENT_REQUIRED';
            return;
        }

        foreach (['filed_date','clerk_name','clerk_address','deputy_signature'] as $k) {
            if (!array_key_exists($k, $block)) {
                $errors[] = 'FILING_ACKNOWLEDGMENT_MISSING_' . strtoupper($k);
            }
        }

        $addr = $block['clerk_address'] ?? null;

        if (!is_array($addr)) {
            $errors[] = 'FILING_ACKNOWLEDGMENT_CLERK_ADDRESS_INVALID';
            return;
        }

        foreach (['line1','city','state','postal_code'] as $k) {
            if (!array_key_exists($k, $addr)) {
                $errors[] = 'CLERK_ADDRESS_MISSING_' . strtoupper($k);
            }
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

    private function requireString(array $content, string $key, array &$errors): void
    {
        if (!array_key_exists($key, $content) || !is_string($content[$key]) || $content[$key] === '') {
            $errors[] = 'MISSING_OR_INVALID_' . strtoupper($key);
        }
    }

    private function requireInt(array $content, string $key, array &$errors): void
    {
        if (!array_key_exists($key, $content) || !is_int($content[$key])) {
            $errors[] = 'MISSING_OR_INVALID_' . strtoupper($key);
        }
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

    private function result(array $errors, array $warnings): array
    {
        return [
            'structural_status'   => empty($errors) ? 'valid' : 'invalid',
            'validation_errors'   => array_values($errors),
            'validation_warnings' => array_values($warnings),
        ];
    }
}
