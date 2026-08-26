<?php

declare(strict_types=1);

use Donut\Gui\StepPath;
use Donut\Parser\WorkflowParser;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// --- parse and back gives the same string ---

foreach ([
	'card-dev.json:steps[0]',
	'card-dev.json:steps[7].then[0]',
	'sync.json:steps[3].steps[1].steps[0]',
	'x.json:steps[2].else[10]',
] as $path) {
	Assert::same($path, (string) StepPath::parse($path), $path);
}

// --- segments ---

Assert::same(
	[['steps', 7], ['then', 0]],
	StepPath::parse('card-dev.json:steps[7].then[0]')->segments(),
);

Assert::same([['steps', 0]], StepPath::parse('card-dev.json:steps[0]')->segments());

// --- workflow name ---

Assert::same('card-dev', StepPath::parse('card-dev.json:steps[7].then[0]')->workflowName());
Assert::same('card-dev', StepPath::workflow('card-dev')->workflowName());

// A path to the whole workflow has no step.
Assert::same([], StepPath::workflow('card-dev')->segments());

// --- what isn't a step path ---

foreach ([
	'card-dev.json',              // the whole workflow, not a step
	'card-dev.json:steps',        // a list, not a step
	'card-dev.json:steps[7].then', // also a list
	'card-dev.json:steps[]',
	'card-dev.json:steps[a]',
	'card-dev.json:notsteps[0]',
	'steps[0]',
	'',
	'card-dev.json:steps[0];rm -rf /',
	"card-dev.json:steps[0]\n", // $ in PCRE allows a trailing \n, we want \z
	' card-dev.json:steps[0]',  // surrounding whitespace would end up in the name
	"card\ndev.json:steps[0]",  // [^:] alone allows \n in the middle of the name too
] as $bad) {
	Assert::exception(
		fn() => StepPath::parse($bad),
		InvalidArgumentException::class,
		"\"{$bad}\" is not a step path.",
	);
}

// --- paths assembled by the template must be parseable ---
//
// A safeguard against assembling and parsing drifting apart: walk the tree
// of all four real workflows, assemble a path the way steps.latte does, and
// verify that parse() accepts it and segments() returns exactly what it was
// built from.

$parser = new WorkflowParser;
$files = \glob(__DIR__ . '/../../docs/workflows/donut/workflows/*.json');
Assert::count(4, $files === false ? [] : $files);

$checked = 0;

$walk = function (array $steps, StepPath $path, array $segments) use (&$walk, &$checked): void {
	foreach ($steps as $i => $step) {
		$at = $path->index($i);
		$here = [...$segments];
		$here[\count($here) - 1][1] = $i;

		Assert::same((string) $at, (string) StepPath::parse((string) $at));
		Assert::same($here, $at->segments(), (string) $at);
		$checked++;

		if ($step instanceof Donut\Format\IfStep) {
			$walk($step->then, $at->child('then'), [...$here, ['then', 0]]);
			$walk($step->else, $at->child('else'), [...$here, ['else', 0]]);

		} elseif ($step instanceof Donut\Format\ForeachStep) {
			$walk($step->steps, $at->child('steps'), [...$here, ['steps', 0]]);
		}
	}
};

foreach ($files === false ? [] : $files as $file) {
	$workflow = $parser->parseFile($file);
	$walk($workflow->steps, StepPath::root($workflow->name), [['steps', 0]]);
}

Assert::same(96, $checked, 'the reference load has 96 steps');
