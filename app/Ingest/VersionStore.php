<?php

namespace App\Ingest;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

// I12: what ingest needs to serve a form, readable while PostgreSQL is down. Versions are immutable, so they
// cache forever (worker LRU -> Redis -> PostgreSQL, read-through). Form state is short-lived and served stale
// when the database is unreachable. Registered as a singleton: the worker caches must outlive the request.
final class VersionStore
{
    public const VERSION_CACHE_ENTRIES = 500;

    public const FORM_STATE_TTL = 60;

    public const FORM_STATE_WORKER_TTL = 10;

    /** @var array<string, array<string, mixed>> insertion order = recency; oldest first */
    private array $versions = [];

    /** @var array<string, array{at: int, state: array<string, mixed>}> */
    private array $forms = [];

    /**
     * @return array{id: string, form_id: string, tenant_id: string, version_no: int, published_at: string, definition: array}|null
     *
     * @throws StoreUnavailable
     */
    public function version(string $formId, string $versionId): ?array
    {
        $key = self::versionKey($formId, $versionId);

        if (isset($this->versions[$key])) {
            $version = $this->versions[$key];
            unset($this->versions[$key]);

            return $this->versions[$key] = $version;
        }

        $version = $this->redisGet($key) ?? $this->versionFromDatabase($formId, $versionId);

        if ($version === null) {
            return null;
        }

        $this->redisSet($key, $version);

        // WHY: bounded because a worker lives for --max-requests requests and could otherwise see every version.
        if (count($this->versions) >= self::VERSION_CACHE_ENTRIES) {
            unset($this->versions[array_key_first($this->versions)]);
        }

        return $this->versions[$key] = $version;
    }

    /**
     * @return array{status: string, tenant_id: string, current_version_id: ?string, versions: list<array{id: string, version_no: int, published_at: string}>}|null
     *
     * @throws StoreUnavailable
     */
    public function form(string $formId): ?array
    {
        $key = self::formKey($formId);
        $cached = $this->forms[$key] ?? null;

        if ($cached !== null && $cached['at'] > time() - self::FORM_STATE_WORKER_TTL) {
            return $cached['state'];
        }

        $state = $this->redisGet($key);

        if ($state === null) {
            try {
                $state = $this->formFromDatabase($formId);
            } catch (QueryException $e) {
                if ($cached === null) {
                    throw new StoreUnavailable('forms', $e);
                }

                Log::warning('version store: serving stale form state', ['form' => $formId, 'error' => $e->getMessage()]);

                return $cached['state'];
            }

            if ($state === null) {
                unset($this->forms[$key]);

                return null;
            }

            $this->redisSet($key, $state, self::FORM_STATE_TTL);
        }

        $this->forms[$key] = ['at' => time(), 'state' => $state];

        return $state;
    }

    /** Called by publish after its commit, so the new version is servable before any read-through. */
    public function put(array $version, array $state): void
    {
        $this->redisSet(self::versionKey($version['form_id'], $version['id']), $version);
        $this->redisSet(self::formKey($version['form_id']), $state, self::FORM_STATE_TTL);
    }

    public function forgetLocal(): void
    {
        $this->versions = [];
        $this->forms = [];
    }

    public static function versionKey(string $formId, string $versionId): string
    {
        return "webform:version:{$formId}:{$versionId}";
    }

    public static function formKey(string $formId): string
    {
        return "webform:form:{$formId}";
    }

    private function versionFromDatabase(string $formId, string $versionId): ?array
    {
        try {
            $row = DB::table('form_versions')->where('id', $versionId)->where('form_id', $formId)
                ->first(['id', 'form_id', 'tenant_id', 'version_no', 'published_at', 'definition']);
        } catch (QueryException $e) {
            throw new StoreUnavailable('form_versions', $e);
        }

        return $row === null ? null : [
            'id' => $row->id, 'form_id' => $row->form_id, 'tenant_id' => $row->tenant_id, 'version_no' => $row->version_no,
            'published_at' => Carbon::parse($row->published_at)->toIso8601String(), 'definition' => json_decode($row->definition, true),
        ];
    }

    /** @throws QueryException */
    private function formFromDatabase(string $formId): ?array
    {
        $form = DB::table('forms')->where('id', $formId)->first(['status', 'tenant_id', 'current_version_id']);

        if ($form === null) {
            return null;
        }

        $versions = DB::table('form_versions')->where('form_id', $formId)->orderBy('version_no')->get(['id', 'version_no', 'published_at']);

        return [
            'status' => $form->status,
            'tenant_id' => $form->tenant_id,
            'current_version_id' => $form->current_version_id,
            'versions' => $versions->map(fn ($v) => ['id' => $v->id, 'version_no' => $v->version_no, 'published_at' => Carbon::parse($v->published_at)->toIso8601String()])->all(),
        ];
    }

    private function redisGet(string $key): ?array
    {
        try {
            $raw = Redis::get($key);
        } catch (Throwable $e) {
            Log::warning('version store: redis read failed', ['key' => $key, 'error' => $e->getMessage()]);

            return null;
        }

        return is_string($raw) ? json_decode($raw, true) : null;
    }

    private function redisSet(string $key, array $value, ?int $ttl = null): void
    {
        try {
            $ttl === null ? Redis::set($key, json_encode($value)) : Redis::setex($key, $ttl, json_encode($value));
        } catch (Throwable $e) {
            Log::warning('version store: redis write failed', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }
}
