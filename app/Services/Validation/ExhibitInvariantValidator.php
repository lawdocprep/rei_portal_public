<?php
declare(strict_types=1);

namespace App\Services\Validation;

final class ExhibitInvariantValidator
{
    /**
     * Phase 18 Validator
     *
     * Enforces:
     *  - Exhibit section presence when required by policy
     *  - Required exhibit_key presence
     *  - Exhibit structural integrity
     *  - Deterministic ordering (contiguous sort_order)
     *  - Array order matches sort_order
     *  - Unique exhibit_label (case-insensitive)
     *  - Unique exhibit_key
     *  - Required document references present
     */
    public function validate(array $artifacts, string $actionType): array
    {
        $errors   = [];
        $warnings = [];

        if (empty($artifacts) || !is_array($artifacts)) {
            return $this->result(['EMPTY_ARTIFACT_BUNDLE'], []);
        }

        $complaint = $this->extractComplaint($artifacts);

        if ($complaint === null) {
            return $this->result(['COMPLAINT_REQUIRED_FOR_EXHIBIT_VALIDATION'], []);
        }

        $rules = ArtifactPolicyRegistry::getArtifactRules($actionType, 'complaint');

        if (!is_array($rules)) {
            return $this->result(['NO_COMPLAINT_POLICY_FOR_ACTION_TYPE'], []);
        }

        $requiredExhibitDefs = $rules['required_exhibits'] ?? [];
        $requiresExhibits    = !empty($requiredExhibitDefs);

        $sections = $complaint['content']['sections'] ?? null;

        if (!is_array($sections)) {
            return $this->result(['COMPLAINT_SECTIONS_REQUIRED'], []);
        }

        $exhibits = $sections['exhibits'] ?? null;

        if ($requiresExhibits) {
            if (!is_array($exhibits) || empty($exhibits)) {
                return $this->result(['EXHIBITS_REQUIRED_BY_POLICY'], []);
            }
        }

        if (!is_array($exhibits)) {
            return $this->result($errors, $warnings);
        }

        $canonicalLabels = [];
        $seenKeys        = [];
        $expectedIndex   = 0;

        foreach ($exhibits as $i => $exhibit) {

            if (!is_array($exhibit)) {
                $errors[] = 'INVALID_EXHIBIT_STRUCTURE_AT_' . $i;
                continue;
            }

            /* -----------------------------------------------------
             * Required structural fields
             * ----------------------------------------------------- */

            $exhibitKey   = $exhibit['exhibit_key']        ?? null;
            $label        = $exhibit['exhibit_label']      ?? null;
            $caseDocId    = $exhibit['source_case_document_id'] ?? null;
            $versionId    = $exhibit['source_version_id']  ?? null;
            $contentHash  = $exhibit['content_hash']       ?? null;
            $sortOrder    = $exhibit['sort_order']         ?? null;

            if (!is_string($exhibitKey) || trim($exhibitKey) === '') {
                $errors[] = 'EXHIBIT_KEY_REQUIRED_AT_' . $i;
            } else {
                if (isset($seenKeys[$exhibitKey])) {
                    $errors[] = 'DUPLICATE_EXHIBIT_KEY_' . strtoupper($exhibitKey);
                }
                $seenKeys[$exhibitKey] = true;
            }

            if (!is_string($label) || trim($label) === '') {
                $errors[] = 'EXHIBIT_LABEL_REQUIRED_AT_' . $i;
            } else {
                $canon = $this->canonicalizeLabel($label);
                if (isset($canonicalLabels[$canon])) {
                    $errors[] = 'DUPLICATE_EXHIBIT_LABEL_' . $canon;
                }
                $canonicalLabels[$canon] = true;
            }

            if (!is_string($caseDocId) || trim($caseDocId) === '') {
                $errors[] = 'EXHIBIT_SOURCE_CASE_DOCUMENT_ID_REQUIRED_AT_' . $i;
            }

            if (!is_string($versionId) || trim($versionId) === '') {
                $errors[] = 'EXHIBIT_SOURCE_VERSION_ID_REQUIRED_AT_' . $i;
            }

            if (!is_string($contentHash) || trim($contentHash) === '') {
                $errors[] = 'EXHIBIT_CONTENT_HASH_REQUIRED_AT_' . $i;
            }

            if (!is_int($sortOrder)) {
                $errors[] = 'EXHIBIT_SORT_ORDER_REQUIRED_AT_' . $i;
            } elseif ($sortOrder !== $expectedIndex) {
                $errors[] = 'NON_CONTIGUOUS_EXHIBIT_SORT_ORDER';
            }

            if (!array_key_exists('title', $exhibit)) {
                $warnings[] = 'EXHIBIT_TITLE_MISSING_AT_' . $i;
            }

            $expectedIndex++;
        }

        /* -----------------------------------------------------
         * Required exhibit_key enforcement
         * ----------------------------------------------------- */

        if (!empty($requiredExhibitDefs)) {

            $presentKeys = array_keys($seenKeys);

            foreach ($requiredExhibitDefs as $def) {

                if (
                    !is_array($def)
                    || empty($def['required'])
                    || empty($def['exhibit_key'])
                ) {
                    continue;
                }

                $requiredKey = (string)$def['exhibit_key'];

                if (!in_array($requiredKey, $presentKeys, true)) {
                    $errors[] = 'REQUIRED_EXHIBIT_MISSING_' . strtoupper($requiredKey);
                }
            }
        }

        return $this->result($errors, $warnings);
    }

    /* ============================================================ */

    private function extractComplaint(array $artifacts): ?array
    {
        foreach ($artifacts as $artifact) {
            if (
                is_array($artifact)
                && ($artifact['artifact_type'] ?? null) === 'complaint'
            ) {
                return $artifact;
            }
        }

        return null;
    }

    private function canonicalizeLabel(string $label): string
    {
        $label = trim($label);
        $label = (string)preg_replace('/\s+/', ' ', $label);
        return mb_strtoupper($label);
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