<?php
declare(strict_types=1);

namespace App\Services\Validation;

final class SummonsValidator
{
   public function validate(array $artifact): array
    {
        $errors   = [];
        $warnings = [];

        /* ------------------------------------------------------------
        * 1️⃣ Envelope checks
        * ------------------------------------------------------------ */

        if (($artifact['artifact_type'] ?? null) !== 'summons') {
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
        * 2️⃣ Schema + lifecycle enforcement (policy-driven)
        * ------------------------------------------------------------ */

        $expectedSchema = ArtifactPolicyRegistry::getSchemaVersion($actionType ?? '');

        if (($artifact['artifact_schema_version'] ?? null) !== $expectedSchema) {
            $errors[] = 'INVALID_SCHEMA_VERSION';
        }

        $rules = ArtifactPolicyRegistry::getArtifactRules($actionType ?? '', 'summons');

        $expectedStage = $rules['required_lifecycle_stage'] ?? null;
        $actualStage   = $metadata['lifecycle_stage'] ?? null;

        if ($expectedStage !== null && $actualStage !== $expectedStage) {
            $errors[] = 'INVALID_LIFECYCLE_STAGE';
        }

        if (!empty($errors)) {
            return $this->result($errors, $warnings);
        }

        /* ------------------------------------------------------------
        * 3️⃣ Content block
        * ------------------------------------------------------------ */

        $content = $artifact['content'] ?? null;

        if (!is_array($content)) {
            return $this->result(['MISSING_CONTENT_BLOCK'], []);
        }

        /* ------------------------------------------------------------
        * 4️⃣ Top-level content whitelist (correct method name)
        * ------------------------------------------------------------ */

        $allowed = ArtifactPolicyRegistry::getContentTopLevelWhitelist(
            $actionType ?? '',
            'summons'
        );

        if (is_array($allowed)) {
            $this->validateAllowedKeys(
                $content,
                $allowed,
                $warnings,
                'CONTENT_SUMMONS'
            );
        }

        /* ------------------------------------------------------------
        * 5️⃣ Structural content validation
        * ------------------------------------------------------------ */

        $this->requireInt($content, 'document_structure_version', $errors);
        $this->requireString($content, 'action_type', $errors);

        if (($content['action_type'] ?? null) !== ($metadata['action_type'] ?? null)) {
            $errors[] = 'ACTION_TYPE_MISMATCH_METADATA_VS_CONTENT';
        }

        $this->requireArray($content, 'attorney_block', $errors);
        $this->validateAttorneyBlock($content['attorney_block'] ?? null, $errors);

        $this->requireArray($content, 'court_heading', $errors);
        $this->validateCourtHeading($content['court_heading'] ?? null, $errors);

        $this->requireArray($content, 'caption', $errors);
        $this->validateCaption($content['caption'] ?? null, $errors);

        $this->requireArray($content, 'service_block', $errors);
        $this->validateServiceBlock($content['service_block'] ?? null, $errors);

        $this->requireArray($content, 'statutory_notice', $errors);
        $this->validateStatutoryNotice($content['statutory_notice'] ?? null, $errors);

        $this->requireArray($content, 'clerk_information', $errors);
        $this->validateClerkInformation($content['clerk_information'] ?? null, $errors);

        $this->requireArray($content, 'accommodation_notice', $errors);
        $this->validateAccommodationNotice($content['accommodation_notice'] ?? null, $errors);

        $this->requireArray($content, 'eviction_notice', $errors);
        $this->validateEvictionNotice($content['eviction_notice'] ?? null, $errors);

        $this->requireArray($content, 'signature_block', $errors);
        $this->validateSignatureBlock($content['signature_block'] ?? null, $errors);

        return $this->result($errors, $warnings);
    }


    /* ============================================================
     * Block Validators (unchanged structural enforcement)
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

        foreach (['plaintiffs','defendants','case_number','title','subtitle'] as $k) {
            if (!array_key_exists($k, $block)) {
                $errors[] = 'CAPTION_MISSING_' . strtoupper($k);
            }
        }
    }

    private function validateServiceBlock($block, array &$errors): void
    {
        if (!is_array($block)) {
            $errors[] = 'SERVICE_BLOCK_REQUIRED';
            return;
        }

        foreach (['recipient_label','recipient_name','recipient_address'] as $k) {
            if (!array_key_exists($k, $block)) {
                $errors[] = 'SERVICE_BLOCK_MISSING_' . strtoupper($k);
            }
        }
    }

    private function validateStatutoryNotice($block, array &$errors): void
    {
        if (!is_array($block)) {
            $errors[] = 'STATUTORY_NOTICE_REQUIRED';
            return;
        }

        if (!is_bool($block['warning_present'] ?? null)) {
            $errors[] = 'STATUTORY_NOTICE_WARNING_PRESENT_INVALID';
        }

        if (!is_int($block['response_time_in_state_days'] ?? null)) {
            $errors[] = 'STATUTORY_NOTICE_IN_STATE_DAYS_INVALID';
        }

        if (!is_int($block['response_time_out_of_state_days'] ?? null)) {
            $errors[] = 'STATUTORY_NOTICE_OUT_OF_STATE_DAYS_INVALID';
        }

        if (!is_array($block['service_methods'] ?? null)) {
            $errors[] = 'STATUTORY_NOTICE_SERVICE_METHODS_INVALID';
        }
    }

    private function validateClerkInformation($block, array &$errors): void
    {
        if (!is_array($block)) {
            $errors[] = 'CLERK_INFORMATION_REQUIRED';
            return;
        }

        if (!is_array($block['filing_locations'] ?? null)) {
            $errors[] = 'CLERK_INFORMATION_FILING_LOCATIONS_INVALID';
        }
    }

    private function validateAccommodationNotice($block, array &$errors): void
    {
        if (!is_array($block)) {
            $errors[] = 'ACCOMMODATION_NOTICE_REQUIRED';
            return;
        }

        if (!is_bool($block['ada_accommodation_required'] ?? null)) {
            $errors[] = 'ACCOMMODATION_NOTICE_ADA_INVALID';
        }

        if (!is_bool($block['interpreter_request_required'] ?? null)) {
            $errors[] = 'ACCOMMODATION_NOTICE_INTERPRETER_INVALID';
        }
    }

    private function validateEvictionNotice($block, array &$errors): void
    {
        if (!is_array($block)) {
            $errors[] = 'EVICTION_NOTICE_REQUIRED';
            return;
        }

        if (!is_bool($block['telephonic_hearing_available'] ?? null)) {
            $errors[] = 'EVICTION_NOTICE_TELEPHONIC_HEARING_AVAILABLE_INVALID';
        }
    }

    private function validateSignatureBlock($block, array &$errors): void
    {
        if (!is_array($block)) {
            $errors[] = 'SIGNATURE_BLOCK_REQUIRED';
            return;
        }

        foreach (['signed_and_sealed_date','clerk','deputy_clerk_signature'] as $k) {
            if (!array_key_exists($k, $block)) {
                $errors[] = 'SIGNATURE_BLOCK_MISSING_' . strtoupper($k);
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

