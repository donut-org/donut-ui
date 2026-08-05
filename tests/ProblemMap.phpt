<?php

declare(strict_types=1);

use Donut\Gui\ProblemMap;
use Donut\Gui\StepPath;
use Donut\Validator\Problem;
use Donut\Validator\Result;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$result = new Result;
$result->add(Problem::error('w.json:steps[0]', 'první'));
$result->add(Problem::warning('w.json:steps[0]', 'druhý'));
$result->add(Problem::error('w.json:steps[1].then[0]', 've větvi'));
$result->add(Problem::warning('w.json', 'k celému workflow'));

$map = ProblemMap::fromResult($result);

// Dva problémy u téhož kroku se oba vrátí, v pořadí, v jakém přišly.
$atZero = $map->at(StepPath::root('w')->index(0));
Assert::count(2, $atZero);
Assert::same('první', $atZero[0]->message);
Assert::same('druhý', $atZero[1]->message);

// Cesta se dá předat i jako řetězec.
Assert::count(2, $map->at('w.json:steps[0]'));

// Vnořený krok dostane svůj problém a nedostane cizí.
$atThen = $map->at(StepPath::root('w')->index(1)->child('then')->index(0));
Assert::count(1, $atThen);
Assert::same('ve větvi', $atThen[0]->message);

// Krok, který problém nemá, dostane prázdné pole, ne null.
Assert::same([], $map->at(StepPath::root('w')->index(9)));

// Problém k celému workflow se nesmí připlést k žádnému kroku.
Assert::count(1, $map->at('w.json'));
Assert::same('k celému workflow', $map->at('w.json')[0]->message);
