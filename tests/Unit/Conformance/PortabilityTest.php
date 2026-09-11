<?php

use App\Forms\DefinitionRules;

$cases = json_decode(file_get_contents(dirname(__DIR__, 3).'/conformance/patterns/portability.json'), true, flags: JSON_THROW_ON_ERROR);

// I8: tests/js/patterns.test.mjs proves every accepted pattern also compiles in JS u-mode.
test('patterns/portability', function (array $case) {
    $errors = (new DefinitionRules)->validate(['fields' => [
        ['id' => 't', 'type' => 'text', 'label' => 'T', 'required' => false, 'rules' => ['pattern' => $case['pattern']]],
    ]]);

    expect($errors === [])->toBe($case['accept'], json_encode($case['pattern']).' => '.json_encode($errors));
})->with(array_map(fn (array $case) => [$case], array_column($cases, null, 'pattern')));
