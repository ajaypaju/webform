<?php

namespace App\Ingest;

use RuntimeException;

// I13: proves a submission came from a page we rendered, and when. Verification and the minimum fill time are
// wired into the submission endpoint next; the token is embedded now.
final class RenderToken
{
    public function __construct(private string $key)
    {
        if ($key === '') {
            throw new RuntimeException('RENDER_TOKEN_KEY is not set.');
        }
    }

    public function issue(string $formId, string $versionId, ?int $issuedAt = null): string
    {
        $payload = implode('|', [$formId, $versionId, $issuedAt ?? time()]);

        return self::encode($payload.'|'.$this->mac($payload));
    }

    /** @return int|null issued_at (unix seconds) when the token is genuine for this form and version, else null */
    public function verify(string $token, string $formId, string $versionId): ?int
    {
        $parts = explode('|', (string) self::decode($token), 4);

        if (count($parts) !== 4 || $parts[0] !== $formId || $parts[1] !== $versionId || ! ctype_digit($parts[2])) {
            return null;
        }

        return hash_equals($this->mac(implode('|', [$parts[0], $parts[1], $parts[2]])), $parts[3]) ? (int) $parts[2] : null;
    }

    private function mac(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->key);
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $token): string|false
    {
        return base64_decode(strtr($token, '-_', '+/'), true);
    }
}
