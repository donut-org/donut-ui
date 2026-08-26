<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

define('TEMP_DIR', __DIR__ . '/tmp/' . getmypid());

// PIDs get recycled; without this, a run on a recycled PID would inherit
// fixtures from a different test, if the previous one stopped on a missing
// Assert and never reached its cleanup at the end.
Tester\Helpers::purge(TEMP_DIR);
