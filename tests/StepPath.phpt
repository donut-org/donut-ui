<?php

declare(strict_types=1);

use Donut\Gui\StepPath;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// Shapes must match what Validator::checkSteps() assembles — pinned in
// donut by the test tests/Donut/Validator.location.phpt.

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

// A problem without a step, belonging to the whole workflow.
Assert::same('card-dev.json', (string) StepPath::workflow('card-dev'));

// The original object doesn't change — the template walks the tree and
// branches one path into several children.
$base = StepPath::root('w')->index(1);
$then = $base->child('then')->index(0);

Assert::same('w.json:steps[1]', (string) $base);
Assert::same('w.json:steps[1].then[0]', (string) $then);
