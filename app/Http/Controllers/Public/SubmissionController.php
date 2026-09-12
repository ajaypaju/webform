<?php

namespace App\Http\Controllers\Public;

use App\Forms\SubmissionValidator;
use App\Http\Controllers\Controller;
use App\Http\ValidationFailed;
use App\Ingest\RateLimited;
use App\Ingest\RateLimiter;
use App\Ingest\RenderToken;
use App\Ingest\SubmissionProducer;
use App\Ingest\VersionStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

final class SubmissionController extends Controller
{
    private const MAX_BODY_BYTES = 262_144;

    private const MAX_DATA_KEYS = 200;

    private const UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function __construct(
        private VersionStore $store,
        private RateLimiter $limiter,
        private RenderToken $tokens,
        private SubmissionValidator $validator,
        private SubmissionProducer $producer,
    ) {}

    public function store(Request $request, string $form): JsonResponse
    {
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            throw new ValidationFailed([['code' => 'body_too_large']], 413);
        }

        $body = $request->validate([
            'submission_id' => ['required', 'string', 'regex:'.self::UUID_V7],
            'form_version_id' => ['required', 'uuid'],
            'render_token' => ['required', 'string'],
            'data' => ['present', 'array'],
            'honeypot' => ['sometimes', 'nullable', 'string'],
        ]);

        if (count($body['data']) > self::MAX_DATA_KEYS) {
            throw new ValidationFailed([['code' => 'too_many_fields', 'field' => 'data']]);
        }

        // 1. Form state (worker cache / Redis / Postgres) and rate limits — nothing else has been touched yet.
        $state = $this->store->form($form);

        if ($state === null || $state['status'] !== 'published') {
            abort(404);
        }

        $this->rateLimit($request, $form, $state['tenant_id']);

        // 2. I13: spam gets a plausible 202 and is never produced. This log line is the audit trail for the
        //    "no lost submissions" claim: a drop here is deliberate and recorded, never silent — info level so no
        //    default log filter hides it.
        if (($reason = $this->spamReason($body, $form)) !== null) {
            Log::info('submission dropped', ['reason' => $reason, 'form' => $form, 'submission_id' => $body['submission_id']]);

            return $this->accepted((string) Str::uuid7(), now());
        }

        // 3. I3: the version must belong to this form and be current or recently superseded.
        $versionId = $body['form_version_id'];
        $versions = $state['versions'];
        $index = array_search($versionId, array_column($versions, 'id'), true);

        if ($index === false) {
            abort(404);
        }

        if ($versionId !== $state['current_version_id']) {
            $supersededAt = Carbon::parse($versions[$index + 1]['published_at']);

            if ($supersededAt->addSeconds(config('ingest.version_grace_seconds'))->isPast()) {
                return response()->json(['code' => 'version_retired', 'current_version_id' => $state['current_version_id']], 409);
            }
        }

        $version = $this->store->version($form, $versionId) ?? abort(404);

        // 4. I6, I7: validated against the pinned version; only visible, known, valid fields survive.
        $result = $this->validator->validate($version['definition'], $body['data']);

        if (! $result->valid) {
            return response()->json(['errors' => $result->errors], 422);
        }

        // 5. I1: received_at is fixed here, before the broker sees it; 202 only after the delivery report.
        $receivedAt = now();

        $this->producer->produce([
            'v' => 1,
            'submission_id' => $body['submission_id'],
            'tenant_id' => $version['tenant_id'],
            'form_id' => $form,
            'form_version_id' => $versionId,
            'received_at' => $receivedAt->format('Y-m-d\TH:i:s.uP'),
            'data' => $result->data === [] ? new stdClass : $result->data,
            'meta' => [
                'ip_hash' => $this->ipHash($request),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'referer' => parse_url((string) $request->headers->get('referer'), PHP_URL_HOST) ?: null,
            ],
        ]);

        return $this->accepted($body['submission_id'], $receivedAt);
    }

    private function rateLimit(Request $request, string $form, string $tenantId): void
    {
        $buckets = [
            'ip_form' => "ip:{$this->ipHash($request)}:form:{$form}",
            'form' => "form:{$form}",
            'tenant' => "tenant:{$tenantId}",
        ];

        foreach ($buckets as $name => $bucket) {
            $limit = config("ingest.rate_limits.{$name}");

            if (($retryAfter = $this->limiter->hit($bucket, $limit['capacity'], $limit['per_second'])) !== null) {
                throw new RateLimited($retryAfter);
            }
        }
    }

    // I13
    private function spamReason(array $body, string $form): ?string
    {
        if (($body['honeypot'] ?? '') !== '') {
            return 'honeypot';
        }

        $issuedAt = $this->tokens->verify($body['render_token'], $form, $body['form_version_id']);

        if ($issuedAt === null) {
            return 'token_invalid';
        }

        $age = now()->timestamp - $issuedAt;

        return match (true) {
            $age < config('ingest.token.min_fill_seconds') => 'token_too_fresh',
            $age > config('ingest.token.max_age_seconds') => 'token_expired',
            default => null,
        };
    }

    private function ipHash(Request $request): string
    {
        $key = config('ingest.ip_hash_key') ?: throw new RuntimeException('IP_HASH_KEY is not set.');

        return hash_hmac('sha256', (string) $request->ip(), $key);
    }

    private function accepted(string $submissionId, Carbon $receivedAt): JsonResponse
    {
        return response()->json(['submission_id' => $submissionId, 'received_at' => $receivedAt->format('Y-m-d\TH:i:s.uP')], 202);
    }
}
