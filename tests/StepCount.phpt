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

// --- rekurze: vnořený foreach uvnitř foreach se počítá celý, ne jen první úroveň ---

$leaf = new SetStep('a', \Donut\Template::parse('1'));

$inner = new ForeachStep(\Donut\Template::parse('{%x%}'), 'x', [$leaf, $leaf]);
$outer = new ForeachStep(\Donut\Template::parse('{%y%}'), 'y', [$leaf, $inner]);

// Přímých potomků $outer jsou dva ($leaf, $inner), ale $inner má další dva
// vlastní — dohromady zmizí 4 vnořené kroky, ne 2, jak by řekl součet jen
// přímých potomků.
Assert::same(4, StepCount::subtree($outer));

$if = new IfStep(new \Donut\Format\Condition(\Donut\Template::parse('{%x%}'), 'not_empty'), [$leaf], [$outer]);

// then má 1 krok, else má $outer s jeho čtyřmi vnořenými + $outer sám → 5.
Assert::same(1 + (1 + 4), StepCount::subtree($if));

// List bez potomků (run/set) nemá co počítat.
Assert::same(0, StepCount::subtree($leaf));

// --- referenční zátěž: sync.json:steps[5] má 14 přímých potomků, ale tři
// z nich jsou foreach s dalším krokem uvnitř — staré (neregresivní) počítání
// hlásilo 14, skutečně zmizí 17 vnořených kroků (+ krok samotný = 18 kroků
// v souboru celkem). ---

$sync = (new WorkflowParser)->parseFile(__DIR__ . '/../../docs/workflows/donut/workflows/sync.json');
$step = StepTree::get($sync, StepPath::parse('sync.json:steps[5]'));

Assert::type(ForeachStep::class, $step);
Assert::same(17, StepCount::subtree($step), 'starý (nerekurzivní) výpočet by tu vrátil 14');

// --- český tvar 1 / 2–4 / 5+ ---

Assert::same('1 vnořený krok', StepCount::label(1));
Assert::same('2 vnořené kroky', StepCount::label(2));
Assert::same('4 vnořené kroky', StepCount::label(4));
Assert::same('5 vnořených kroků', StepCount::label(5));
Assert::same('17 vnořených kroků', StepCount::label(17));
