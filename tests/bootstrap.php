<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

define('TEMP_DIR', __DIR__ . '/tmp/' . getmypid());

// PIDy se recyklují; bez tohohle by běh na recyklovaném PID zdědil fixtury
// z jiného testu, kdyby ten předchozí skončil na chybějícím Assert a nedoběhl
// ke svému úklidu na konci.
Tester\Helpers::purge(TEMP_DIR);
