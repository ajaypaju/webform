<?php

use App\Forms\DefinitionRules;

foreach (glob(dirname(__DIR__, 3).'/conformance/definitions/*.json') as $file) {
    $cases = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

    test('definitions/'.basename($file, '.json'), function (array $case) {
        $errors = (new DefinitionRules)->validate($case['definition']);

        expect($errors)->toBe($case['errors'])
            ->and($errors === [])->toBe($case['valid']);
    })->with(array_map(fn (array $case) => [$case], array_column($cases, null, 'name')));
}
