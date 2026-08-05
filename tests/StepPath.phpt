<?php

declare(strict_types=1);

use Donut\Gui\StepPath;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// Tvary musí odpovídat tomu, co skládá Validator::checkSteps() — připnuto
// v donutu testem tests/Donut/Validator.location.phpt.

Assert::same('card-dev.json:steps', (string) StepPath::root('card-dev'));

Assert::same(
	'card-dev.json:steps[7]',
	(string) StepPath::root('card-dev')->index(7)
);

Assert::same(
	'card-dev.json:steps[1].then[0]',
	(string) StepPath::root('card-dev')->index(1)->child('then')->index(0)
);

Assert::same(
	'card-dev.json:steps[1].else[0]',
	(string) StepPath::root('card-dev')->index(1)->child('else')->index(0)
);

Assert::same(
	'card-dev.json:steps[2].steps[0]',
	(string) StepPath::root('card-dev')->index(2)->child('steps')->index(0)
);

// Původní objekt se nemění — šablona prochází strom a jednu cestu větví
// do víc dětí.
$base = StepPath::root('w')->index(1);
$then = $base->child('then')->index(0);

Assert::same('w.json:steps[1]', (string) $base);
Assert::same('w.json:steps[1].then[0]', (string) $then);
