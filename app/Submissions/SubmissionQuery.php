<?php

namespace App\Submissions;

use App\Http\ValidationFailed;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// One form's submissions, newest first, keyset-paginated on (received_at, id) — the order of the
// (tenant_id, form_id, received_at DESC, id DESC) index. Query builder only: this is a hot path (I15).
final class SubmissionQuery
{
    public const FIELD_ID = '/^[a-z0-9_]{1,40}$/';

    private ?string $from = null;

    private ?string $to = null;

    private ?string $versionId = null;

    /** @var list<string> JSON documents for `data @> ?`, OR-ed (a value may be a string or a JSON scalar) */
    private array $containments = [];

    public function __construct(private string $tenantId, private string $formId) {}

    /** @param  array<string, mixed>  $params  from, to, version_id, field + value */
    public static function fromRequest(string $tenantId, string $formId, array $params): self
    {
        $query = new self($tenantId, $formId);
        $errors = [];

        foreach (['from', 'to'] as $bound) {
            if (($params[$bound] ?? '') !== '') {
                $parsed = rescue(fn () => Carbon::parse($params[$bound]), null, false);
                $parsed === null ? $errors[] = ['code' => 'timestamp', 'field' => $bound] : $query->{$bound} = $parsed->format('Y-m-d H:i:s.uP');
            }
        }

        if (($params['version_id'] ?? '') !== '') {
            Str::isUuid($params['version_id']) ? $query->versionId = $params['version_id'] : $errors[] = ['code' => 'uuid', 'field' => 'version_id'];
        }

        if (($params['field'] ?? '') !== '' || array_key_exists('value', $params)) {
            $field = (string) ($params['field'] ?? '');

            if (preg_match(self::FIELD_ID, $field) !== 1) {
                $errors[] = ['code' => 'field_id', 'field' => 'field'];
            } elseif (! is_string($params['value'] ?? null)) {
                $errors[] = ['code' => 'required', 'field' => 'value'];
            } else {
                $query->containments = self::containments($field, $params['value']);
            }
        }

        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        return $query;
    }

    public function builder(): Builder
    {
        $query = DB::table('submissions')
            ->where('tenant_id', $this->tenantId)
            ->where('form_id', $this->formId)
            ->orderByDesc('received_at')
            ->orderByDesc('id');

        if ($this->from !== null) {
            $query->where('received_at', '>=', $this->from);
        }

        if ($this->to !== null) {
            $query->where('received_at', '<=', $this->to);
        }

        if ($this->versionId !== null) {
            $query->where('form_version_id', $this->versionId);
        }

        if ($this->containments !== []) {
            // WHY: jsonb @> is what the GIN (jsonb_path_ops) index answers; OR-ing the string and scalar readings of
            // the value keeps a query-string filter exact without asking the caller for the field's type.
            $query->where(function (Builder $q) {
                foreach ($this->containments as $document) {
                    $q->orWhereRaw('data @> ?::jsonb', [$document]);
                }
            });
        }

        return $query;
    }

    /**
     * Rows after $cursor (exclusive), at most $limit; returns the rows and the cursor for the next page or null.
     *
     * @return array{0: list<object>, 1: ?string}
     */
    public function page(int $limit, ?string $cursor): array
    {
        $query = $this->builder();

        if ($cursor !== null) {
            $query->whereRaw('(received_at, id) < (?, ?)', self::decodeCursor($cursor));
        }

        $rows = $query->limit($limit + 1)->get(['id', 'received_at', 'form_version_id', 'data']);
        $page = $rows->take($limit)->all();

        return [$page, $rows->count() > $limit ? self::encodeCursor(end($page)) : null];
    }

    public static function encodeCursor(object $row): string
    {
        return base64_encode(Carbon::parse($row->received_at)->format('Y-m-d\TH:i:s.uP').'|'.$row->id);
    }

    /** @return array{0: string, 1: string} */
    public static function decodeCursor(string $cursor): array
    {
        $parts = explode('|', (string) base64_decode($cursor, true), 2);

        if (count($parts) !== 2 || ! Str::isUuid($parts[1]) || strtotime($parts[0]) === false) {
            throw new ValidationFailed([['code' => 'cursor', 'field' => 'cursor']]);
        }

        return $parts;
    }

    /** @return list<string> */
    private static function containments(string $field, string $value): array
    {
        $documents = [json_encode([$field => $value])];
        $scalar = json_decode($value);

        if (is_int($scalar) || is_float($scalar) || is_bool($scalar)) {
            $documents[] = json_encode([$field => $scalar]);
        }

        return $documents;
    }
}
