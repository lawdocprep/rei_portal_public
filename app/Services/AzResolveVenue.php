<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use PDO;

final class AzResolveVenue implements ProceduralAction
{
    public function run(ProcedureContext $ctx): array
    {
        $pdo = $ctx->db;

        /*
        ------------------------------------------------------------
        Resolve property for case
        ------------------------------------------------------------
        */

        $st = $pdo->prepare("
            SELECT property_id
            FROM cases
            WHERE id = :case_id
        ");

        $st->execute([
            ':case_id' => $ctx->caseId
        ]);

        $propertyId = $st->fetchColumn();

        if (!$propertyId) {
            throw new RuntimeException('PROPERTY_NOT_FOUND');
        }

        /*
        ------------------------------------------------------------
        Resolve venue
        ------------------------------------------------------------
        */

        $resolver = new VenueResolverService($pdo);

        $venue = $resolver->resolveByPropertyId(
            tenantId: $ctx->tenantId,
            propertyId: $propertyId
        );

        if (!$venue || !isset($venue['court_id'])) {
            throw new RuntimeException('VENUE_NOT_FOUND');
        }

        /*
        ------------------------------------------------------------
        Persist court
        ------------------------------------------------------------
        */

        $upd = $pdo->prepare("
            UPDATE cases
            SET court_id = :court_id
            WHERE id = :case_id
        ");

        $upd->execute([
            ':court_id' => $venue['court_id'],
            ':case_id' => $ctx->caseId
        ]);

        /*
        ------------------------------------------------------------
        Emit canonical event
        ------------------------------------------------------------
        */

        $ctx->emitCaseEvent(
            'venue_resolved',
            [
                'court_id' => $venue['court_id'],
                'source'   => 'procedural_action'
            ]
        );

        return [
            'court_id' => $venue['court_id']
        ];
    }
}