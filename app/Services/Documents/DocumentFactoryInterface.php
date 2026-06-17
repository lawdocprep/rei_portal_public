<?php
declare(strict_types=1);

namespace App\Services\Documents;

use App\Services\ProcedureContext;

interface DocumentFactoryInterface
{
    /**
     * Resolve or create the canonical document for a procedural action.
     *
     * Implementations MUST be:
     * - idempotent
     * - replay-safe
     * - execution-time only
     *
     * @return string The document_id
     */
    public function resolveOrCreate(ProcedureContext $ctx): string;

    /**
     * Return the document_type_key produced by this factory.
     *
     * This is used for:
     * - uniqueness guarantees
     * - auditing
     * - routing (future rendering/finalization)
     */
    public function getDocumentTypeKey(): string;
}
