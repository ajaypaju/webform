<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\ValidationFailed;
use App\Models\Form;
use App\Submissions\CsvExport;
use App\Submissions\SubmissionQuery;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// The submissions viewer: the same SubmissionQuery and CsvExport::columns the /v1 endpoints use, rendered by Blade.
// Nothing here is the tenant's own content — every value is what an anonymous visitor typed.
final class SubmissionController extends Controller
{
    private const PAGE_LIMIT = 200;

    private const FILTERS = ['from', 'to', 'version_id', 'field', 'value'];

    public function index(Request $request, Form $form, TenantContext $tenant): View
    {
        $filters = array_filter(array_intersect_key($request->query(), array_flip(self::FILTERS)), fn ($v) => is_string($v) && $v !== '');
        $limit = min(max((int) $request->query('limit', 50), 1), self::PAGE_LIMIT);
        $versions = $this->versions($form);
        $columns = CsvExport::columns($form->id);
        $rows = [];
        $next = $previous = null;
        $errors = [];

        try {
            $query = SubmissionQuery::fromRequest($tenant->tenantId, $form->id, $filters);

            // WHY: keyset in both directions and no total: counting a partitioned table on every page view is
            // exactly the load this design keeps off PostgreSQL. "N of many" is what the customer sees.
            if (is_string($before = $request->query('before'))) {
                [$rows, $previous] = $query->pageBefore($limit, $before);
                $next = $rows === [] ? null : SubmissionQuery::encodeCursor(end($rows));
            } else {
                [$rows, $next] = $query->page($limit, $request->query('cursor'));
                $previous = $rows !== [] && is_string($request->query('cursor')) ? SubmissionQuery::encodeCursor($rows[0]) : null;
            }
        } catch (ValidationFailed $e) {
            $errors = $e->errors;
        }

        $link = fn (array $page) => route('dashboard.submissions', ['form' => $form->id, ...$filters, ...($limit !== 50 ? ['limit' => $limit] : []), ...$page]);

        return view('dashboard.submissions.index', [
            'form' => $form,
            'columns' => $columns,
            'versions' => $versions,
            'rows' => array_map(fn ($row) => self::row($row, $versions, $columns), $rows),
            'filters' => $filters,
            'filterErrors' => $errors,
            'nextUrl' => $next === null ? null : $link(['cursor' => $next]),
            'previousUrl' => $previous === null ? null : $link(['before' => $previous]),
            'exportUrl' => route('dashboard.submissions.export', ['form' => $form->id, ...$filters]),
            'pageUrl' => rtrim(config('app.public_url'), '/')."/f/{$form->id}",
        ]);
    }

    public function show(Form $form, string $submission, TenantContext $tenant): View
    {
        $row = DB::table('submissions')->where('tenant_id', $tenant->tenantId)->where('form_id', $form->id)->where('id', $submission)
            ->first(['id', 'received_at', 'form_version_id', 'data', 'meta']);

        abort_if($row === null, 404);

        $version = $this->versions($form)[$row->form_version_id];
        $data = json_decode($row->data, true);
        $meta = json_decode($row->meta, true);

        return view('dashboard.submissions.show', [
            'form' => $form,
            'submission' => [
                'id' => $row->id,
                'received_at' => Carbon::parse($row->received_at)->format('Y-m-d\TH:i:s.uP'),
                'version_no' => $version['version_no'],
                'version_id' => $row->form_version_id,
            ],
            // I3/I5: labels come from the version this submission was validated against, not the current one.
            'answers' => array_map(fn ($id, $label) => [
                'id' => $id, 'label' => $label,
                'answered' => array_key_exists($id, $data) && $data[$id] !== null,
                'value' => CsvExport::cell($data[$id] ?? null),
            ], array_keys($version['labels']), $version['labels']),
            'meta' => [
                'ip_hash' => $meta['ip_hash'] ?? null,
                'user_agent' => mb_strimwidth((string) ($meta['user_agent'] ?? ''), 0, 80, '…'),
                'referer' => $meta['referer'] ?? null,
            ],
        ]);
    }

    /** @return array<string, array{version_no: int, labels: array<string, string>}> keyed by version id */
    private function versions(Form $form): array
    {
        return $form->versions()->orderBy('version_no')->get()
            ->mapWithKeys(fn ($v) => [$v->id => ['version_no' => $v->version_no, 'labels' => array_column($v->definition['fields'], 'label', 'id')]])
            ->all();
    }

    /**
     * One table row, cells in column order. A field the submission's version never had is `absent`; one it had
     * but that holds no answer (optional, or hidden by visibility) is `empty` — the table shows the two differently.
     */
    private static function row(object $row, array $versions, array $columns): array
    {
        $data = json_decode($row->data, true);
        $version = $versions[$row->form_version_id];

        return [
            'id' => $row->id,
            'received_at' => Carbon::parse($row->received_at)->format('Y-m-d H:i:s'),
            'version_no' => $version['version_no'],
            'cells' => array_map(fn ($column) => [
                'state' => ! array_key_exists($column['id'], $version['labels']) ? 'absent' : (($data[$column['id']] ?? null) === null ? 'empty' : 'value'),
                'value' => CsvExport::cell($data[$column['id']] ?? null),
            ], $columns),
        ];
    }
}
