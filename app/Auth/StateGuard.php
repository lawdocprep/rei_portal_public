<?php
namespace App\Auth;

use PDO;

final class StateGuard
{
    public function __construct(private PDO $db) {}

    public function validateTransition(array $req, array $transition): bool
    {
        // Example for action endpoints with action_id in request
        $actionId = $req['action_id'] ?? null;
        if (!$actionId) return true; // not applicable

        $sql = "SELECT status FROM legal_actions WHERE id = :id AND tenant_id = :t";
        $st = $this->db->prepare($sql);
        $st->execute([':id' => $actionId, ':t' => (int)$req['tenant_id']]);
        $status = $st->fetchColumn();
        if (!$status) return false;

        return $status === ($transition['from'] ?? '');
    }
}
