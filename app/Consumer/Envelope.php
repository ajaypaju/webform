<?php

namespace App\Consumer;

use DateTimeImmutable;

// Turns a topic message into a submissions row, or names why it can't be one. Validates the envelope only: the
// data inside was validated at acceptance against a version that may since have been superseded, so it is stored
// as-is (I3).
final class Envelope
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const ID_FIELDS = ['submission_id', 'tenant_id', 'form_id', 'form_version_id'];

    /**
     * @return array{id: string, tenant_id: string, form_id: string, form_version_id: string, data: string, meta: string, received_at: string}|string
     *         a row, or the rejection reason
     */
    public static function parse(?string $payload): array|string
    {
        $envelope = json_decode((string) $payload, true);

        if (! is_array($envelope)) {
            return 'invalid_json';
        }

        if (($envelope['v'] ?? null) !== 1) {
            return 'unsupported_version';
        }

        foreach ([...self::ID_FIELDS, 'received_at', 'data'] as $field) {
            if (! array_key_exists($field, $envelope)) {
                return "missing_field:{$field}";
            }
        }

        foreach (self::ID_FIELDS as $field) {
            if (! is_string($envelope[$field]) || preg_match(self::UUID, $envelope[$field]) !== 1) {
                return "invalid_uuid:{$field}";
            }
        }

        if (! is_array($envelope['data'])) {
            return 'invalid_data';
        }

        $receivedAt = self::parseTimestamp($envelope['received_at']);

        if ($receivedAt === null) {
            return 'invalid_received_at';
        }

        return [
            'id' => $envelope['submission_id'],
            'tenant_id' => $envelope['tenant_id'],
            'form_id' => $envelope['form_id'],
            'form_version_id' => $envelope['form_version_id'],
            'data' => json_encode($envelope['data'] === [] ? new \stdClass : $envelope['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'meta' => json_encode(is_array($envelope['meta'] ?? null) && $envelope['meta'] !== [] ? $envelope['meta'] : new \stdClass, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'received_at' => $receivedAt->format('Y-m-d H:i:s.uP'),
        ];
    }

    private static function parseTimestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        foreach (['Y-m-d\TH:i:s.uP', 'Y-m-d\TH:i:sP'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value);

            if ($parsed !== false && $parsed->format($format) === $value) {
                return $parsed;
            }
        }

        return null;
    }
}
