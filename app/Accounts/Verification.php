<?php

namespace App\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

// Email verification without a mail transport: the signed link is written to the api log. Sending it is designed,
// not built (ARCHITECTURE §11); everything else — signing, expiry, the verified gate — is real.
final class Verification
{
    public const TTL_HOURS = 24;

    public static function url(User $user): string
    {
        return self::urlFor($user->id, $user->email);
    }

    public static function urlFor(string $userId, string $email): string
    {
        return URL::temporarySignedRoute('verify', now()->addHours(self::TTL_HOURS), ['user' => $userId, 'hash' => self::hash($email)]);
    }

    /**
     * WHY: with no mail transport the link only proves "you can read the log", and the logged-in user already can;
     * in a local build it is shown on screen so the gate can be exercised without docker logs. Never elsewhere.
     */
    public static function showOnScreen(): bool
    {
        return app()->environment('local');
    }

    public static function hash(string $email): string
    {
        return sha1(strtolower($email));
    }

    /** Stands in for the verification email. */
    public static function log(User $user): void
    {
        Log::info('verification link (mail not configured; this line is the email)', ['email' => $user->email, 'url' => self::url($user)]);
    }

    /** Stands in for the "someone tried to sign up with your address" email. */
    public static function logExisting(string $email): void
    {
        Log::info('signup with an existing email (mail not configured; this line is the email to its owner)', ['email' => $email]);
    }
}
