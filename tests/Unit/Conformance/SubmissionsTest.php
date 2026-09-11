<?php

use App\Forms\SubmissionValidator;

// One test per fixture case; cases named redos_* must also finish in under 500 ms (I8).
foreach (glob(dirname(__DIR__, 3).'/conformance/submissions/*.json') as $file) {
    $cases = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

    test('submissions/'.basename($file, '.json'), function (array $case) {
        $start = hrtime(true);

        $result = (new SubmissionValidator)->validate($case['definition'], $case['input']);

        $elapsedMs = (hrtime(true) - $start) / 1e6;

        expect($result->valid)->toBe($case['valid'])
            ->and($result->errors)->toBe($case['errors'])
            ->and($result->data)->toBe($case['output']);

        if (str_starts_with($case['name'], 'redos_')) {
            expect($elapsedMs)->toBeLessThan(500);
        }
    })->with(array_map(fn (array $case) => [$case], array_column($cases, null, 'name')));
}
