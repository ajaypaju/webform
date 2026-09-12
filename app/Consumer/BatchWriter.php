<?php

namespace App\Consumer;

use Illuminate\Database\ConnectionInterface;

// I2: one transaction per batch. Hot path: raw multi-row SQL, no models.
final class BatchWriter
{
    private const COLUMNS = ['id', 'tenant_id', 'form_id', 'form_version_id', 'data', 'meta', 'received_at'];

    public function __construct(private ConnectionInterface $db) {}

    /**
     * @param  list<array<string, string>>  $rows  parsed envelopes (Envelope::parse)
     * @return array{unique: int, inserted: int}
     */
    public function write(array $rows): array
    {
        // I2: a multi-row INSERT can't ON CONFLICT against itself, so duplicates inside the batch collapse here first.
        $unique = [];

        foreach ($rows as $row) {
            $unique[$row['id']] ??= $row;
        }

        if ($unique === []) {
            return ['unique' => 0, 'inserted' => 0];
        }

        return $this->db->transaction(function () use ($unique) {
            // I2 step 1: claim ids globally; only ids never seen before come back.
            $claimed = $this->db->select(
                'insert into submission_ids (id, received_at) values '.implode(', ', array_fill(0, count($unique), '(?, ?)')).' on conflict (id) do nothing returning id',
                array_merge(...array_map(fn ($row) => [$row['id'], $row['received_at']], array_values($unique))),
            );

            $fresh = array_map(fn ($row) => $unique[$row->id], $claimed);

            // I2 step 2: rows only for the claimed ids, in one statement.
            if ($fresh !== []) {
                $this->db->insert(
                    'insert into submissions ('.implode(', ', self::COLUMNS).') values '.implode(', ', array_fill(0, count($fresh), '('.implode(', ', array_fill(0, count(self::COLUMNS), '?')).')')),
                    array_merge(...array_map(fn ($row) => array_map(fn ($column) => $row[$column], self::COLUMNS), $fresh)),
                );
            }

            // I2 step 3: the transaction commits on return; the caller commits offsets only after that.
            return ['unique' => count($unique), 'inserted' => count($fresh)];
        });
    }
}
