<?php
declare(strict_types=1);

namespace App\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use Throwable;

final class TokenVerifier
{
    // Hard policy (v0.1): we only accept this algorithm.
    // (Later: support key rotation + kid + multiple algs only if required.)
    private const ALG = 'RS256';

    // Allow small clock skew between services
    private const LEEWAY_SECONDS = 60;

    public function __construct(
        private string $issuer,
        private string $publicKeyPem
    ) {
        if ($this->issuer === '') {
            throw new RuntimeException('TokenVerifier: issuer must not be empty');
        }
        if ($this->publicKeyPem === '') {
            throw new RuntimeException('TokenVerifier: publicKeyPem must not be empty');
        }
        // Basic sanity check (not cryptographic validation)
        if (!str_contains($this->publicKeyPem, 'BEGIN PUBLIC KEY') && !str_contains($this->publicKeyPem, 'BEGIN RSA PUBLIC KEY')) {
            throw new RuntimeException('TokenVerifier: publicKeyPem does not look like a PEM public key');
        }
    }

    /**
     * Verify and decode a JWT.
     *
     * REQUIRED invariants:
     * - signature MUST be valid (RS256)
     * - token MUST NOT be expired (exp)
     * - if present, nbf MUST be satisfied
     * - iss MUST match configured issuer
     *
     * @return array<string,mixed>
     */
    public function verify(string $jwt): array
    {
        $jwt = trim($jwt);
        if ($jwt === '') {
            throw new RuntimeException('TokenVerifier: empty token');
        }

        // Basic structural validation: header.payload.signature
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('TokenVerifier: invalid JWT format');
        }

        // Defensive: validate header alg matches expected (avoid alg confusion)
        $header = $this->decodePartJson($parts[0], 'header');
        $alg = $header['alg'] ?? null;
        if (!is_string($alg) || $alg !== self::ALG) {
            throw new RuntimeException('TokenVerifier: unsupported alg');
        }

        // Configure leeway for exp/nbf validation inside library
        JWT::$leeway = self::LEEWAY_SECONDS;

        try {
            $decoded = JWT::decode($jwt, new Key($this->publicKeyPem, self::ALG));
        } catch (Throwable $e) {
            // Fail closed on any decode/signature/time validation error
            throw new RuntimeException('TokenVerifier: invalid token', 0, $e);
        }

        // Convert decoded stdClass to array
        $claims = json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($claims)) {
            throw new RuntimeException('TokenVerifier: decoded claims not an object');
        }

        // Enforce required claims
        $iss = $claims['iss'] ?? null;
        if (!is_string($iss) || $iss !== $this->issuer) {
            throw new RuntimeException('TokenVerifier: issuer mismatch');
        }

        // Require exp (fail closed if missing)
        $exp = $claims['exp'] ?? null;
        if (!is_int($exp)) {
            // Some issuers encode numbers as floats; accept numeric strings safely
            if (is_numeric($exp)) {
                $exp = (int)$exp;
            } else {
                throw new RuntimeException('TokenVerifier: missing/invalid exp');
            }
        }

        // Library already checks exp/nbf; we keep these guards for clarity + future portability
        $now = time();
        if ($exp <= ($now - self::LEEWAY_SECONDS)) {
            throw new RuntimeException('TokenVerifier: token expired');
        }

        if (isset($claims['nbf'])) {
            $nbf = $claims['nbf'];
            if (!is_int($nbf)) {
                if (is_numeric($nbf)) {
                    $nbf = (int)$nbf;
                } else {
                    throw new RuntimeException('TokenVerifier: invalid nbf');
                }
            }
            if ($nbf > ($now + self::LEEWAY_SECONDS)) {
                throw new RuntimeException('TokenVerifier: token not yet valid');
            }
        }

        return $claims;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodePartJson(string $b64Url, string $label): array
    {
        $json = $this->base64UrlDecode($b64Url);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException("TokenVerifier: invalid {$label} JSON");
        }
        return $data;
    }

    private function base64UrlDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            throw new RuntimeException('TokenVerifier: base64url decode failed');
        }
        return $decoded;
    }
}
