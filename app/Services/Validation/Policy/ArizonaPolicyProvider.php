<?php
declare(strict_types=1);

namespace App\Services\Validation\Policy;

use App\Services\Validation\Canonicalization\CaptionCanonicalizer;
use App\Services\Validation\Canonicalization\CourtHeadingCanonicalizer;

final class ArizonaPolicyProvider implements StatePolicyProviderInterface
{
    /**
     * Phase 17 Migration Notes
     * -----------------------
     * Complaint schema upgraded to v2:
     *  - document_structure_version: 2
     *  - signature lives under content.sections.signature (NOT content.signature_block)
     *  - v2 section keys:
     *      property_address, demand, relief, exhibits, signature
     *
     * Exhibits requirement:
     *  - Phase 17 filing freeze must be achievable before exhibits subsystem is fully enabled.
     *  - Policy sets requires_exhibits=false for now.
     *  - Validator may still support an env-gated requirement if/when turned on later.
     */
    public function getPolicies(): array
    {
        return [
            'az_forcible_detainer' => [

                // ✅ Phase 17: bump schema version to align with v2 complaint output
                'schema_version' => 2,

                'required_artifacts' => [
                    'complaint',
                    'summons',
                    'certificate_compulsory_arbitration',
                ],

                /* -----------------------------------------------------
                 * Global Whitelists
                 * ----------------------------------------------------- */

                'envelope_whitelist' => [
                    'artifact_type',
                    'format',
                    'preview_only',
                    'artifact_schema_version',
                    'metadata',
                    'content',
                    'structural_status',
                    'validation_errors',
                    'validation_warnings',
                ],

                'metadata_whitelist' => [
                    'action_type',
                    'legal_action_id',
                    'intent_hash',
                    'origin',
                    'lifecycle_stage',
                    'generated_from_intent',
                ],

                /* -----------------------------------------------------
                 * Caption Whitelist (Phase 16 format)
                 * ----------------------------------------------------- */

                'caption_whitelist' => [
                    'allowed_caption_keys' => [
                        'plaintiffs',
                        'defendants',
                        'case_number',
                        'title',
                        'statutory_reference',
                    ],
                    'allowed_party_keys' => [
                        'intent_role',
                        'type',
                        'case_party_id',
                        'actor_id',
                        'name',
                        'derived_from_roles',
                        'address',
                        'fictitious',
                    ],
                ],

                /* -----------------------------------------------------
                 * Cross-Artifact Consistency Rules
                 * ----------------------------------------------------- */

                'cross_artifact_consistency' => [

                    [
                        'field' => 'caption',
                        'artifacts' => [
                            'complaint',
                            'summons',
                            'certificate_compulsory_arbitration',
                        ],
                        'required' => true,
                        'canonicalizer' => CaptionCanonicalizer::class,
                        'error_code_prefix' => 'CAPTION',
                    ],

                    [
                        'field' => 'court_heading',
                        'artifacts' => [
                            'complaint',
                            'summons',
                            'certificate_compulsory_arbitration',
                        ],
                        'required' => true,
                        'canonicalizer' => CourtHeadingCanonicalizer::class,
                        'error_code_prefix' => 'COURT_HEADING',
                    ],
                ],

                /* -----------------------------------------------------
                 * Artifact Rules
                 * ----------------------------------------------------- */

                'artifact_rules' => [

                    /* =============================
                     * Complaint (v2)
                     * ============================= */

                    'complaint' => [
                        'required_lifecycle_stage'   => 'pre_filing',
                        'requires_eviction_warning'  => true,

                        /**
                         * Phase 18:
                         * Exhibits now formally defined at policy layer.
                         * requires_exhibits remains for backward compatibility.
                         * It should evaluate to true when required_exhibits is non-empty.
                         */
                        'requires_exhibits' => true,

                        /**
                         * Phase 18 — Required Exhibit Definitions
                         *
                         * exhibit_key:
                         *   Stable artifact-level identifier (used in artifact snapshot).
                         *
                         * document_type_keys:
                         *   Allowed case_documents.document_type_key values that satisfy requirement.
                         *
                         * required:
                         *   If true → validator must enforce presence.
                         *
                         * title:
                         *   Default display title if artifact snapshot does not override.
                         */
                        'required_exhibits' => [
                            [
                                'exhibit_key' => 'demand_for_possession',
                                'document_type_keys' => [
                                    'az_demand_for_possession',
                                    'demand_notice',
                                ],
                                'required' => true,
                                'title' => 'Demand for Possession',
                            ],
                        ],

                        /**
                         * Phase 17 complaint v2 sections
                         */
                        'required_sections' => [
                            'property_address',
                            'demand',
                            'relief',
                            'exhibits',
                            'signature',
                        ],

                        'content_top_level_whitelist' => [
                            'document_structure_version',
                            'action_type',
                            'attorney_block',
                            'court_heading',
                            'caption',
                            'eviction_warning_block',
                            'sections',
                        ],
                    ],

                    /* =============================
                     * Summons
                     * ============================= */

                    'summons' => [
                        'required_lifecycle_stage' => 'pre_clerk_stamp',

                        'content_top_level_whitelist' => [
                            'document_structure_version',
                            'action_type',
                            'attorney_block',
                            'court_heading',
                            'caption',
                            'service_block',
                            'statutory_notice',
                            'clerk_information',
                            'accommodation_notice',
                            'eviction_notice',
                            'signature_block',
                        ],
                    ],

                    /* =============================
                     * Certificate of Compulsory Arbitration
                     * ============================= */

                    'certificate_compulsory_arbitration' => [
                        'required_lifecycle_stage' => 'pre_filing',

                        'content_top_level_whitelist' => [
                            'document_structure_version',
                            'action_type',
                            'attorney_block',
                            'court_heading',
                            'caption',
                            'certificate_body',
                            'signature_block',
                            'filing_acknowledgment',
                        ],
                    ],
                ],
            ],
        ];
    }
}