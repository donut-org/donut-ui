<?php

declare(strict_types=1);

use Donut\Gui\ProblemMap;
use Donut\Gui\StepPath;
use Donut\Validator\Problem;
use Donut\Validator\Result;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$result = new Result;
$result->add(Problem::error('w.json:steps[0]', 'first'));
$result->add(Problem::warning('w.json:steps[0]', 'second'));
$result->add(Problem::error('w.json:steps[1].then[0]', 'in the branch'));
$result->add(Problem::warning('w.json', 'for the whole workflow'));

$map = ProblemMap::fromResult($result);

// Two problems at the same step are both returned, in the order they came in.
$atZero = $map->at(StepPath::root('w')->index(0));
Assert::count(2, $atZero);
Assert::same('first', $atZero[0]->message);
Assert::same('second', $atZero[1]->message);

// A path can also be passed as a string.
Assert::count(2, $map->at('w.json:steps[0]'));

// A nested step gets its own problem and not someone else's.
$atThen = $map->at(StepPath::root('w')->index(1)->child('then')->index(0));
Assert::count(1, $atThen);
Assert::same('in the branch', $atThen[0]->message);

// A step with no problem gets an empty array, not null.
Assert::same([], $map->at(StepPath::root('w')->index(9)));

// A problem for the whole workflow must not get mixed up with any step.
Assert::count(1, $map->at('w.json'));
Assert::same('for the whole workflow', $map->at('w.json')[0]->message);
