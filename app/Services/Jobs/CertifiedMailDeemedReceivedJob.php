<?php
// app/Jobs/CertifiedMailDeemedReceivedJob.php

declare(strict_types=1);

namespace App\Services\Jobs;

use PDO;
use App\Services\Events\CaseEventService;

final class CertifiedMailDeemedReceivedJob
{
    private CaseEventService $events;

    public function __construct(
        private PDO $db
    ) {
        $this->events = new CaseEventService($db);
    }

    public function run(): void
    {
        $sql = <<<SQL
        SELECT
            d.id        AS deadline_id,
            d.tenant_id,
            d.case_id,
            d.payload
        FROM case_procedural_deadlines d
        WHERE d.obligation_key = 'az_demand_service_proof'
          AND d.status IN ('pending','blocked')
          AND d.payload ? 'certified_mail'
          AND (d.payload->'certified_mail'->>'deemed_received_at')::timestamptz <= now()
          AND NOT (d.payload ? 'satisfaction')
        SQL;

        $st = $this->db->query($sql);

        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {

            $payload = json_decode($row['payload'], true);
            $certified = $payload['certified_mail'] ?? [];

            $eventPayload = [
                'deadline_id'        => $row['deadline_id'],
                'tracking_number'    => $certified['tracking_number'] ?? null,
                'deemed_received_at' => $certified['deemed_received_at'] ?? null,
                'carrier'            => 'usps',
                'source'             => 'cron',
            ];

            $eventId = $this->events->emitEvent(
                'demand_delivery.certified_mail_deemed_received',
                $eventPayload,
                $row['tenant_id'],
                $row['case_id'],
                $this->systemActorId()
            );

            if (!is_string($eventId) || $eventId === '') {
                throw new \RuntimeException('CERTIFIED_MAIL_DEEMED_RECEIVED_EVENT_FAILED');
            }
        }
    }

    private function systemActorId(): string
    {
        return '00000000-0000-0000-0000-000000000000';
    }
}
