<?php

namespace App\Tenancy;

final class ApiKey
{
    private const PREFIX = 'wf_';

    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /** A new key: "wf_" + 32 random bytes, base62. Shown to the tenant once; only hash() is stored. */
    public static function generate(): string
    {
        $bytes = array_values(unpack('C*', random_bytes(32)));
        $encoded = '';

        // Long division of the byte string by 62, most significant byte first.
        while ($bytes !== []) {
            $remainder = 0;
            $quotient = [];

            foreach ($bytes as $byte) {
                $value = $remainder * 256 + $byte;
                $remainder = $value % 62;

                if ($quotient !== [] || $value >= 62) {
                    $quotient[] = intdiv($value, 62);
                }
            }

            $encoded = self::ALPHABET[$remainder].$encoded;
            $bytes = $quotient;
        }

        return self::PREFIX.$encoded;
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    /** The part of a key a tenant may see again after creation: enough to tell keys apart, useless to authenticate. */
    public static function prefix(string $key): string
    {
        return substr($key, 0, 8);
    }
}
