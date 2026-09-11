<?php

use App\Forms\PublishCompat;

// I5
foreach (glob(dirname(__DIR__, 3).'/conformance/publish/*.json') as $file) {
    $cases = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

    test('publish/'.basename($file, '.json'), function (array $case) {
        $errors = (new PublishCompat)->check($case['prior_versions'], $case['draft']);

        expect($errors)->toBe($case['errors'])
            ->and($errors === [])->toBe($case['valid']);
    })->with(array_map(fn (array $case) => [$case], array_column($cases, null, 'name')));
}
