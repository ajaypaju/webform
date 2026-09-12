<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\ConsumerGroup;

// The whole path: browser POST -> validate -> topic (I1) -> consumer -> PostgreSQL row (I2), exactly what was accepted.
it('stores exactly the accepted data of a posted submission, without hidden-field values', function () {
    $group = ConsumerGroup::fresh();
    ['form' => $form, 'version' => $version] = liveForm();
    $body = payload($form, $version, ['email' => ' ada@example.com ', 'plan' => 'free', 'seats' => 4, 'code' => 'ABC']);

    $response = submit($form, $body)->assertStatus(202);

    expect(ConsumerGroup::consumer($group)->runOnce())->toMatchArray(['count' => 1, 'inserted' => 1]);

    $row = DB::connection('pgsql')->table('submissions')->where('id', $body['submission_id'])->first();
    expect($row)->not->toBeNull()
        ->and($row->form_id)->toBe($form)
        ->and($row->form_version_id)->toBe($version)
        // WHY: jsonb reorders object keys; compare content, strictly typed, not order.
        ->and(collect(json_decode($row->data, true))->sortKeys()->all())->toBe(['code' => 'ABC', 'email' => 'ada@example.com', 'plan' => 'free'])
        ->and(json_decode($row->meta, true))->toMatchArray(['user_agent' => 'PestBrowser/1.0', 'referer' => 'customer.example'])
        ->and(json_decode($row->meta, true)['ip_hash'])->not->toContain(IP)
        ->and(DB::connection('pgsql')->scalar('select to_char(received_at at time zone \'UTC\', \'YYYY-MM-DD"T"HH24:MI:SS.US"+00:00"\') from submissions where id = ?', [$body['submission_id']]))->toBe($response->json('received_at'));

    // Posting the same id again (a client retry) is a second record on the topic and no second row.
    submit($form, $body)->assertStatus(202);
    expect(ConsumerGroup::consumer($group)->runOnce())->toMatchArray(['count' => 1, 'inserted' => 0, 'duplicates' => 1])
        ->and(DB::connection('pgsql')->table('submissions')->where('id', $body['submission_id'])->count())->toBe(1);
});
