<?php

test('health endpoint is up', function () {
    $this->get('/up')->assertOk();
});
