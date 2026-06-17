<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use App\Services\Events\CaseEventService;

final class ProcedureContext
{
    private CaseEventService $events;

    public function __construct(
        public PDO $db,
        public string $tenantId,
        public string $caseId,
        public string $actorId,
        public string $requestId,
        public string $requestedAction,
        public string $nowIso,
        public array $larPayload,
    ) {
        $this->events = new CaseEventService($db);
    }

    public function emitCaseEvent(string $eventType, array $payload): void
    {
        $eventId = $this->events->emitEvent(
            $eventType,
            $payload,
            $this->tenantId,
            $this->caseId,
            $this->actorId
        );

        if (!is_string($eventId) || $eventId === '') {
            throw new RuntimeException('PROCEDURE_EVENT_WRITE_FAILED');
        }
    }
}