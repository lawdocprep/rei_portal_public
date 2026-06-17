<?php
declare(strict_types=1);

namespace App\Services\Validation\Pipeline;

use App\Services\Validation\ValidationPipelineContext;

/**
 * ExhibitInvariantStage
 *
 * Enforces cross-artifact invariants related to complaint exhibits.
 *
 * Phase 16 Rules:
 * - Structural only
 * - No DB access
 * - No legal sufficiency analysis
 * - Deterministic
 */
final class ExhibitInvariantStage implements ValidationStageInterface
{
    public function name(): string
    {
        return 'exhibit_invariant';
    }

    public function execute(ValidationPipelineContext $ctx): void
    {
        // Use indexed artifacts for deterministic access
        $byType = $ctx->artifactsByType ?? [];

        if (!isset($byType['complaint'])) {
            return;
        }

        $complaint = $byType['complaint'];

        if (!is_array($complaint)) {
            return;
        }

        $content = $complaint['content'] ?? null;

        if (!is_array($content)) {
            return;
        }

        $sections = $content['sections'] ?? null;

        if (!is_array($sections)) {
            return;
        }

        /*
         * Invariant:
         * Complaint must contain an exhibits section block
         * (even if empty — structural presence required)
         */

        if (!array_key_exists('exhibits', $sections)) {
            $ctx->addError('EXHIBITS_BLOCK_REQUIRED');
            return;
        }

        /*
         * If exhibits block exists but is malformed,
         * emit structural error (future-proofing).
         */
        if (!is_array($sections['exhibits'])) {
            $ctx->addError('EXHIBITS_BLOCK_INVALID_STRUCTURE');
        }
    }
}