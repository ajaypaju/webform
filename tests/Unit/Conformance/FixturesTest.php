<?php

// WHY: Pest keys dataset entries by case name, so a duplicate name would silently swallow a case.
test('case names are unique within each fixture file', function () {
    foreach (glob(dirname(__DIR__, 3).'/conformance/*/*.json') as $file) {
        $cases = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $names = array_column($cases, isset($cases[0]['name']) ? 'name' : 'pattern');

        expect(array_diff_key($names, array_unique($names)))
            ->toBe([], 'duplicate case names in '.basename(dirname($file)).'/'.basename($file));
    }
});
