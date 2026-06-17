<?php
declare(strict_types=1);

namespace App\Validation;

use PDO;

final class CourtValidator
{
    public static function assertSelectable(?string $courtId, PDO $db): void
    {
        // Legacy orphans allowed
        if ($courtId === null) {
            return;
        }

        // UUID format check
        if (!preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $courtId
        )) {
            throw new \DomainException('INVALID_COURT_ID');
        }

        // Existence + lifecycle check
        $stmt = $db->prepare(
            'SELECT 1 FROM courts WHERE id = :id AND status = \'active\''
        );
        $stmt->execute(['id' => $courtId]);

        if (!$stmt->fetchColumn()) {
            throw new \DomainException('COURT_NOT_SELECTABLE');
        }
    }
}
