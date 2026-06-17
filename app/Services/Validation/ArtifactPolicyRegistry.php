<?php
declare(strict_types=1);

namespace App\Services\Validation;

use App\Services\Validation\Policy\StatePolicyProviderInterface;
use App\Services\Validation\Policy\ArizonaPolicyProvider;

/**
 * Phase 16.8 (Hardened)
 *
 * State-aware structural policy registry.
 *
 * - Pluggable state providers
 * - Structural configuration only
 * - Defensive nested access
 * - Deterministic boot
 */
final class ArtifactPolicyRegistry
{
    /**
     * @var array<string, array>
     */
    private static array $policies = [];

    private static bool $booted = false;

    /* ------------------------------------------------------------
     * Boot
     * ------------------------------------------------------------ */

    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::registerProvider(new ArizonaPolicyProvider());

        self::$booted = true;
    }

    public static function registerProvider(
        StatePolicyProviderInterface $provider
    ): void {
        foreach ($provider->getPolicies() as $actionType => $policy) {

            if (!is_string($actionType) || $actionType === '') {
                continue;
            }

            if (!is_array($policy)) {
                continue;
            }

            self::$policies[$actionType] = $policy;
        }
    }

    /* ------------------------------------------------------------
     * Base Accessor
     * ------------------------------------------------------------ */

    public static function getActionPolicy(string $actionType): ?array
    {
        self::boot();

        if ($actionType === '') {
            return null;
        }

        return self::$policies[$actionType] ?? null;
    }

    /* ------------------------------------------------------------
     * Simple Accessors
     * ------------------------------------------------------------ */

    public static function getRequiredArtifacts(string $actionType): ?array
    {
        $policy = self::getActionPolicy($actionType);
        return is_array($policy['required_artifacts'] ?? null)
            ? $policy['required_artifacts']
            : null;
    }

    public static function getSchemaVersion(string $actionType): ?int
    {
        $policy = self::getActionPolicy($actionType);
        $value = $policy['schema_version'] ?? null;

        return is_int($value) ? $value : null;
    }

    public static function getEnvelopeWhitelist(string $actionType): ?array
    {
        $policy = self::getActionPolicy($actionType);
        return is_array($policy['envelope_whitelist'] ?? null)
            ? $policy['envelope_whitelist']
            : null;
    }

    public static function getMetadataWhitelist(string $actionType): ?array
    {
        $policy = self::getActionPolicy($actionType);
        return is_array($policy['metadata_whitelist'] ?? null)
            ? $policy['metadata_whitelist']
            : null;
    }

    public static function getCaptionWhitelist(string $actionType): ?array
    {
        $policy = self::getActionPolicy($actionType);
        return is_array($policy['caption_whitelist'] ?? null)
            ? $policy['caption_whitelist']
            : null;
    }

    /* ------------------------------------------------------------
     * Artifact-Level Accessors
     * ------------------------------------------------------------ */

    public static function getArtifactRules(
        string $actionType,
        string $artifactType
    ): ?array {
        $policy = self::getActionPolicy($actionType);

        if (!is_array($policy)) {
            return null;
        }

        $rules = $policy['artifact_rules'][$artifactType] ?? null;

        return is_array($rules) ? $rules : null;
    }

    public static function getContentTopLevelWhitelist(
        string $actionType,
        string $artifactType
    ): ?array {
        $rules = self::getArtifactRules($actionType, $artifactType);

        return is_array($rules['content_top_level_whitelist'] ?? null)
            ? $rules['content_top_level_whitelist']
            : null;
    }
}