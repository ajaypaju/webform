<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Submissions\CsvExport;
use App\Submissions\SubmissionQuery;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SubmissionController extends Controller
{
    private const PAGE_LIMIT = 200;

    public function index(Request $request, Form $form, TenantContext $tenant): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 50), 1), self::PAGE_LIMIT);
        $query = SubmissionQuery::fromRequest($tenant->tenantId, $form->id, $request->query());

        [$rows, $next] = $query->page($limit, $request->query('cursor'));

        return response()->json([
            'data' => array_map(fn ($row) => [
                'id' => $row->id,
                'received_at' => Carbon::parse($row->received_at)->format('Y-m-d\TH:i:s.uP'),
                'form_version_id' => $row->form_version_id,
                'data' => json_decode($row->data, true),
            ], $rows),
            'next_cursor' => $next,
        ]);
    }

    // I15
    public function export(Request $request, Form $form, TenantContext $tenant): StreamedResponse
    {
        $query = SubmissionQuery::fromRequest($tenant->tenantId, $form->id, $request->query());
        $export = new CsvExport($query, $tenant->tenantId, CsvExport::columns($form->id));

        return response()->streamDownload(function () use ($export) {
            $out = fopen('php://output', 'w');
            $export->write($out);
            fclose($out);
        }, "submissions-{$form->id}.csv", ['Content-Type' => 'text/csv; charset=utf-8', 'X-Content-Type-Options' => 'nosniff']);
    }
}
