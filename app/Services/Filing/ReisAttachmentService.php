<?php
declare(strict_types=1);

namespace App\Services\Filing;

use RuntimeException;

/**
 * ReisAttachmentService
 *
 * Resolves the static Residential Eviction Information Sheet (REIS)
 * required to accompany every residential eviction summons.
 *
 * The REIS is a regulatory attachment and:
 *
 * - is NOT generated from a template
 * - is NOT stored in filing_documents
 * - is appended to the summons during rendering
 * - must exist at a deterministic filesystem location
 *
 * This service centralizes resolution and validation of that file.
 */
final class ReisAttachmentService
{
    /**
     * Cached absolute path.
     */
    private ?string $cachedPath = null;

    /**
     * Public entry point.
     *
     * Returns the validated absolute path to the REIS PDF.
     *
     * @throws RuntimeException if the file is missing or unreadable
     */
    public function getPath(): string
    {
        if ($this->cachedPath !== null) {
            return $this->cachedPath;
        }

        $path = $this->resolveDefaultPath();

        $this->assertReadablePdf($path);

        $this->cachedPath = $path;

        return $path;
    }

    /**
     * Determine the default filesystem location.
     *
     * This uses a deterministic relative path from the application root
     * and works in:
     *
     * - local development
     * - Docker containers
     * - AWS deployments
     */
    private function resolveDefaultPath(): string
    {
        /*
        Directory layout assumption:
        app/
            Services/
            Static/
                REIS.pdf
        */

        $base = dirname(__DIR__, 2);

        return $base . '/Static/REIS.pdf';
    }

    /**
     * Validate the attachment file.
     *
     * Guarantees:
     *
     * - file exists
     * - file is readable
     * - file is not empty
     */
    private function assertReadablePdf(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException(
                'REIS_ATTACHMENT_NOT_FOUND'
            );
        }

        if (!is_readable($path)) {
            throw new RuntimeException(
                'REIS_ATTACHMENT_NOT_READABLE'
            );
        }

        if (filesize($path) === 0) {
            throw new RuntimeException(
                'REIS_ATTACHMENT_EMPTY'
            );
        }
    }
}