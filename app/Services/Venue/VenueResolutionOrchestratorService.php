<?php
declare(strict_types=1);

namespace App\Services\Venue;

use PDO;
use RuntimeException;
use App\Procedures\ResolveVenueProcedure;
use App\Services\Procedures\ProcedureContext;

final class VenueResolutionOrchestratorService
{
    public function __construct(
        private PDO $db,
        private ResolveVenueProcedure $resolver
    ) {}

    /**
     * Attempt venue resolution when property becomes resolvable.
     *
     * Safe to call repeatedly.
     */
    public function resolveIfReady(
        string $tenantId,
        string $caseId,
        string $legalActionId,
        string $actorId
    ): void {

        if (!$this->propertyHasCoordinates($tenantId, $caseId)) {
            return;
        }

        if ($this->venueAlreadyResolved($tenantId, $caseId)) {
            return;
        }

        $ctx = new ProcedureContext(
            db: $this->db,
            tenantId: $tenantId,
            caseId: $caseId,
            legalActionId: $legalActionId,
            actorId: $actorId
        );

        $this->resolver->execute($ctx);
    }

    /**
     * Used before filing to guarantee correctness after boundary updates.
     */
    public function revalidateIfDatasetChanged(
        string $tenantId,
        string $caseId,
        string $legalActionId,
        string $actorId
    ): void {

        $currentDataset = $this->getCurrentBoundaryDatasetId();

        $assignedDataset = $this->getAssignedDatasetId(
            $tenantId,
            $caseId
        );

        if ($assignedDataset === null) {
            return;
        }

        if ($assignedDataset !== $currentDataset) {

            $ctx = new ProcedureContext(
                db: $this->db,
                tenantId: $tenantId,
                caseId: $caseId,
                legalActionId: $legalActionId,
                actorId: $actorId
            );

            $this->resolver->execute($ctx);
        }
    }

    private function propertyHasCoordinates(
        string $tenantId,
        string $caseId
    ): bool {

        $st = $this->db->prepare("
            SELECT 1
            FROM case_properties cp
            JOIN properties p
              ON p.id = cp.property_id
            WHERE cp.tenant_id = :tenant
              AND cp.case_id = :case
              AND p.geo_lat IS NOT NULL
              AND p.geo_lng IS NOT NULL
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case' => $caseId
        ]);

        return (bool)$st->fetchColumn();
    }

    private function venueAlreadyResolved(
        string $tenantId,
        string $caseId
    ): bool {

        $st = $this->db->prepare("
            SELECT 1
            FROM case_venue_assignments
            WHERE tenant_id = :tenant
              AND case_id = :case
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case' => $caseId
        ]);

        return (bool)$st->fetchColumn();
    }

    private function getAssignedDatasetId(
        string $tenantId,
        string $caseId
    ): ?string {

        $st = $this->db->prepare("
            SELECT dataset_id
            FROM case_venue_assignments
            WHERE tenant_id = :tenant
              AND case_id = :case
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case' => $caseId
        ]);

        $value = $st->fetchColumn();

        return is_string($value) ? $value : null;
    }

    private function getCurrentBoundaryDatasetId(): ?string
    {
        $st = $this->db->query("
            SELECT id
            FROM jurisdiction_boundary_datasets
            WHERE is_active = true
            LIMIT 1
        ");

        $value = $st->fetchColumn();

        return is_string($value) ? $value : null;
    }
}