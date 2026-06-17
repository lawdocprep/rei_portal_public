<?php
declare(strict_types=1);

namespace App\Services\Filing\Templates;

use RuntimeException;

/**
 * TemplateRegistry
 *
 * Deterministic resolver for compiled filing templates.
 *
 * This registry replaces dynamic TemplateResolver logic with
 * an explicit routing table while preserving the same routing
 * dimensions:
 *
 *     tenant_key
 *     court_type
 *     action_type
 *     document_type
 *
 * This ensures:
 *
 * - deterministic template selection
 * - multi-jurisdiction readiness
 * - safe future expansion
 * - identical behavior to earlier resolver logic
 *
 * The registry is intentionally static.
 */
final class TemplateRegistry
{
    /**
     * Deterministic routing table.
     *
     * tenant → court → action → document → template class
     */
    private const TEMPLATE_MAP = [

        /*
        ============================================================
        REI Law Firm — Arizona — Superior Court — Forcible Detainer
        ============================================================
        */

        'reilaw' => [

            'superior' => [

                'az_forcible_detainer' => [

                    /*
                    ------------------------------------------------
                    Demand for Possession
                    ------------------------------------------------
                    */

                    'az_demand_for_possession'
                        => ReiLawAZDemandForPossessionTemplate::class,

                    /*
                    ------------------------------------------------
                    Complaint
                    ------------------------------------------------
                    */

                    'complaint'
                        => ReiLawFDComplaintTemplate::class,

                    /*
                    ------------------------------------------------
                    Summons
                    ------------------------------------------------
                    */

                    'summons'
                        => ReiLawFDSummonsTemplate::class,

                    /*
                    ------------------------------------------------
                    Certificate of Compulsory Arbitration
                    ------------------------------------------------
                    */

                    'certificate_compulsory_arbitration'
                        => ReiLawFDCertificateCompulsoryArbitrationTemplate::class,
                ],
            ],
        ],
    ];

    /**
     * Resolve a compiled template deterministically.
     */
    public function resolve(
        string $tenantKey,
        string $courtType,
        string $actionType,
        string $documentType
    ): array {

        $tenantKey   = trim($tenantKey);
        $courtType   = trim($courtType);
        $actionType  = trim($actionType);
        $documentType = trim($documentType);

        if ($tenantKey === '') {
            throw new RuntimeException('TENANT_KEY_REQUIRED');
        }

        if ($courtType === '') {
            throw new RuntimeException('COURT_TYPE_REQUIRED');
        }

        if ($actionType === '') {
            throw new RuntimeException('ACTION_TYPE_REQUIRED');
        }

        if ($documentType === '') {
            throw new RuntimeException('DOCUMENT_TYPE_REQUIRED');
        }

        if (!isset(self::TEMPLATE_MAP
            [$tenantKey]
            [$courtType]
            [$actionType]
            [$documentType])) {

            throw new RuntimeException(
                'TEMPLATE_NOT_REGISTERED: '
                . $tenantKey . ':'
                . $courtType . ':'
                . $actionType . ':'
                . $documentType
            );
        }

        $class =
            self::TEMPLATE_MAP
                [$tenantKey]
                [$courtType]
                [$actionType]
                [$documentType];

        if (!class_exists($class)) {
            throw new RuntimeException(
                'TEMPLATE_CLASS_NOT_FOUND: ' . $class
            );
        }

        if (!method_exists($class, 'getTemplate')) {
            throw new RuntimeException(
                'INVALID_TEMPLATE_CLASS: ' . $class
            );
        }

        $template = $class::getTemplate();

        if (!is_array($template)) {
            throw new RuntimeException(
                'TEMPLATE_INVALID_FORMAT: ' . $class
            );
        }

        return $template;
    }

    /**
     * Backward-compatible helper.
     *
     * Allows simple document-only resolution if needed.
     */
    public function resolveByDocumentType(
        string $documentType
    ): array {

        return $this->resolve(
            'reilaw',
            'superior',
            'az_forcible_detainer',
            $documentType
        );
    }

    /**
     * Check if template exists.
     */
    public function exists(
        string $tenantKey,
        string $courtType,
        string $actionType,
        string $documentType
    ): bool {

        return isset(
            self::TEMPLATE_MAP
                [$tenantKey]
                [$courtType]
                [$actionType]
                [$documentType]
        );
    }

    /**
     * Return full routing table.
     */
    public function all(): array
    {
        return self::TEMPLATE_MAP;
    }
}