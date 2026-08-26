<?php

declare(strict_types=1);

use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\SetStep;
use Donut\Gui\StepCount;
use Donut\Gui\StepPath;
use Donut\Gui\StepTree;
use Donut\Parser\WorkflowParser;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// --- recursion: a foreach nested inside a foreach counts fully, not just the first level ---

$leaf = new SetStep('a', \Donut\Template::parse('1'));

$inner = new ForeachStep(\Donut\Template::parse('{%x%}'), 'x', [$leaf, $leaf]);
$outer = new ForeachStep(\Donut\Template::parse('{%y%}'), 'y', [$leaf, $inner]);

// $outer has two direct children ($leaf, $inner), but $inner has two more of
// its own — 4 nested steps disappear in total, not 2, as summing only the
// direct children would say.
Assert::same(4, StepCount::subtree($outer));

$if = new IfStep(new \Donut\Format\Condition(\Donut\Template::parse('{%x%}'), 'not_empty'), [$leaf], [$outer]);

// then has 1 step, else has $outer with its four nested steps + $outer itself → 5.
Assert::same(1 + (1 + 4), StepCount::subtree($if));

// A leaf with no children (run/set) has nothing to count.
Assert::same(0, StepCount::subtree($leaf));

// --- reference load: sync.json:steps[5] has 14 direct children, but three
// of them are a foreach with another step inside — the old (non-recursive)
// count reported 14, in reality 17 nested steps disappear (+ the step
// itself = 18 steps in the file in total). ---

$sync = (new WorkflowParser)->parseFile(__DIR__ . '/../../docs/workflows/donut/workflows/sync.json');
$step = StepTree::get($sync, StepPath::parse('sync.json:steps[5]'));

Assert::type(ForeachStep::class, $step);
Assert::same(17, StepCount::subtree($step), 'the old (non-recursive) count would return 14 here');

// --- English plural: 1 / 2+ ---

Assert::same('1 nested step', StepCount::label(1));
Assert::same('2 nested steps', StepCount::label(2));
Assert::same('4 nested steps', StepCount::label(4));
Assert::same('5 nested steps', StepCount::label(5));
Assert::same('17 nested steps', StepCount::label(17));
