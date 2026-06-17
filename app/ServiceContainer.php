<?php
declare(strict_types=1);

namespace App\Infrastructure;

use PDO;
use App\Services\Events\CaseEventService;

final class ServiceContainer
{
    public function __construct(
        private PDO $db
    ) {}

    private ?CaseEventService $caseEventService = null;

    public function caseEvents(): CaseEventService
    {
        return $this->caseEventService
            ??= new CaseEventService($this->db);
    }
}