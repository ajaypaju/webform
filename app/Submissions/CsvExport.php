<?php

namespace App\Submissions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// I15: streams one form's submissions as CSV over keyset chunks; never holds more than one chunk. Columns are the
// union of field ids over every published version (I5 is what makes that meaningful), each headed by its most
// recent label, so rows from older versions export correctly after renames and deletions.
final class CsvExport
{
    public const CHUNK = 1000;

    private const FIXED = ['submission_id', 'received_at', 'form_version_id'];

    /**
     * @param  list<array{id: string, label: string}>  $columns  from columns()
     */
    public function __construct(private SubmissionQuery $query, private string $tenantId, private array $columns) {}

    /** @return list<array{id: string, label: string}> field ids in first-seen order, labelled by the latest version that has them */
    public static function columns(string $formId): array
    {
        $labels = [];

        foreach (DB::table('form_versions')->where('form_id', $formId)->orderBy('version_no')->pluck('definition') as $definition) {
            foreach (json_decode($definition, true)['fields'] as $field) {
                $labels[$field['id']] = $field['label'];
            }
        }

        // WHY: two ids may share a label (or a label may equal a fixed column); only then does the id join the header.
        $taken = array_count_values([...self::FIXED, ...array_values($labels)]);
        $headers = array_map(fn ($id, $label) => $taken[$label] > 1 ? "{$label} ({$id})" : $label, array_keys($labels), $labels);
        $headers = array_combine(array_keys($labels), $headers);

        return array_map(fn ($id) => ['id' => $id, 'label' => $headers[$id]], array_keys($labels));
    }

    /** Writes the whole CSV to $out (a stream resource). Runs in its own transaction with the tenant set (I11). */
    public function write($out): void
    {
        DB::transaction(function () use ($out) {
            // WHY: the middleware's transaction — and its set_config — ended before the stream started.
            DB::statement("select set_config('app.tenant_id', ?, true)", [$this->tenantId]);

            $this->row($out, [...self::FIXED, ...array_column($this->columns, 'label')]);
            $cursor = null;

            do {
                [$rows, $cursor] = $this->query->page(self::CHUNK, $cursor);

                foreach ($rows as $row) {
                    $data = json_decode($row->data, true);
                    $this->row($out, [
                        $row->id,
                        Carbon::parse($row->received_at)->format('Y-m-d\TH:i:s.uP'),
                        $row->form_version_id,
                        ...array_map(fn ($column) => self::cell($data[$column['id']] ?? null), $this->columns),
                    ]);
                }

                flush();
            } while ($cursor !== null);
        });
    }

    /** @param  list<string>  $cells */
    private function row($out, array $cells): void
    {
        // WHY: escape '' (not '\'): RFC 4180 doubles quotes and has no backslash escaping.
        fputcsv($out, array_map(self::guard(...), $cells), ',', '"', '', "\r\n");
    }

    /** I10: a cell a spreadsheet would evaluate gets a leading apostrophe. */
    public static function guard(string $cell): string
    {
        return $cell !== '' && str_contains("=+-@\t\r", $cell[0]) ? "'".$cell : $cell;
    }

    public static function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => implode('; ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $value)),
            default => (string) $value,
        };
    }
}
