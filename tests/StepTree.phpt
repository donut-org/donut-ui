<?php

declare(strict_types=1);

use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\SetStep;
use Donut\Format\Workflow;
use Donut\Gui\StepPath;
use Donut\Gui\StepTree;
use Donut\Parser\WorkflowParser;
use Donut\Template;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$parser = new WorkflowParser;
$files = \glob(__DIR__ . '/../../docs/workflows/donut/workflows/*.json');
Assert::count(4, $files === false ? [] : $files);

/** @return list<StepPath> */
$allPaths = function (Workflow $workflow): array {
	$paths = [];

	$walk = function (array $steps, StepPath $path) use (&$walk, &$paths): void {
		foreach ($steps as $i => $step) {
			$at = $path->index($i);
			$paths[] = $at;

			if ($step instanceof IfStep) {
				$walk($step->then, $at->child('then'));
				$walk($step->else, $at->child('else'));

			} elseif ($step instanceof ForeachStep) {
				$walk($step->steps, $at->child('steps'));
			}
		}
	};

	$walk($workflow->steps, StepPath::root($workflow->name));

	return $paths;
};

// --- invariants over the whole reference load ---
//
// Hand-picked cases would cover a handful of shapes; this covers 96 steps
// to depth 3 at once. If get() and replace() drifted apart by one index or
// confused a branch, it would fail on the very first workflow.

$checked = 0;

foreach ($files === false ? [] : $files as $file) {
	$workflow = $parser->parseFile($file);
	$before = \serialize($workflow);

	foreach ($allPaths($workflow) as $at) {
		$checked++;

		// get + replace target the same node
		Assert::same(
			$before,
			\serialize(StepTree::replace($workflow, $at, StepTree::get($workflow, $at))),
			"replace(get) at {$at}",
		);

		// remove followed by insert back returns the original tree
		Assert::same(
			$before,
			\serialize(StepTree::insert(
				StepTree::remove($workflow, $at),
				$at,
				StepTree::get($workflow, $at),
			)),
			"remove+insert at {$at}",
		);

		// moveUp and moveDown are involutions — swapping twice at the same
		// position returns the original tree. (Combining moveDown+moveUp on
		// the same path doesn't guarantee this: the path targets a
		// position, not a step, so the second swap already targets a
		// different neighbor than the one the first swap moved the step
		// away from.)
		Assert::same(
			$before,
			\serialize(StepTree::moveDown(StepTree::moveDown($workflow, $at), $at)),
			"moveDown twice at {$at}",
		);
		Assert::same(
			$before,
			\serialize(StepTree::moveUp(StepTree::moveUp($workflow, $at), $at)),
			"moveUp twice at {$at}",
		);
	}
}

Assert::same(96, $checked, 'the reference load has 96 steps');

// --- specific behavior on a small tree ---

$set = fn(string $key): SetStep => new SetStep(key: $key, value: Template::parse('x'));

$workflow = new Workflow(name: 'w', steps: [
	$set('a'),
	new IfStep(
		condition: new Donut\Format\Condition(left: Template::parse('{%x%}'), op: 'not_empty'),
		then: [$set('t1'), $set('t2')],
	),
	$set('b'),
]);

$keys = function (Workflow $w): array {
	return \array_map(
		fn($s): string => $s instanceof SetStep ? $s->key : 'if',
		$w->steps,
	);
};

// moveUp swaps with its neighbor
Assert::same(['if', 'a', 'b'], $keys(StepTree::moveUp($workflow, StepPath::parse('w.json:steps[1]'))));

// at the edge it's a no-op, not an error — the template doesn't render the
// arrow, but a hand-crafted POST must not fail
Assert::same(['a', 'if', 'b'], $keys(StepTree::moveUp($workflow, StepPath::parse('w.json:steps[0]'))));
Assert::same(['a', 'if', 'b'], $keys(StepTree::moveDown($workflow, StepPath::parse('w.json:steps[2]'))));

// insert into the middle shifts the rest
Assert::same(
	['a', 'new', 'if', 'b'],
	$keys(StepTree::insert($workflow, StepPath::parse('w.json:steps[1]'), $set('new'))),
);

// insert at the end
Assert::same(
	['a', 'if', 'b', 'new'],
	$keys(StepTree::insert($workflow, StepPath::parse('w.json:steps[3]'), $set('new'))),
);

// remove closes the hole
Assert::same(['a', 'b'], $keys(StepTree::remove($workflow, StepPath::parse('w.json:steps[1]'))));

// deleting an if takes the whole branch with it
Assert::count(2, StepTree::remove($workflow, StepPath::parse('w.json:steps[1]'))->steps);

// --- working inside a branch ---

$branched = StepTree::insert($workflow, StepPath::parse('w.json:steps[1].then[0]'), $set('t0'));
$if = $branched->steps[1];
Assert::type(IfStep::class, $if);
Assert::same(['t0', 't1', 't2'], \array_map(fn($s): string => $s->key, $if->then));

// an empty else branch — inserting into it is the only way to fill it
$doElse = StepTree::insert($workflow, StepPath::parse('w.json:steps[1].else[0]'), $set('e0'));
Assert::same(['e0'], \array_map(fn($s): string => $s->key, $doElse->steps[1]->else));

// --- invalid paths ---

Assert::exception(
	fn() => StepTree::get($workflow, StepPath::parse('w.json:steps[9]')),
	OutOfRangeException::class,
);

// descending into a branch on a step that doesn't have one
Assert::exception(
	fn() => StepTree::get($workflow, StepPath::parse('w.json:steps[0].then[0]')),
	OutOfRangeException::class,
);

// insert right after the end of the list is fine, any further isn't
Assert::exception(
	fn() => StepTree::insert($workflow, StepPath::parse('w.json:steps[4]'), $set('x')),
	OutOfRangeException::class,
);

// --- get() and apply() (replace/insert/remove/…) must agree even on a hole in the keys ---
//
// Workflow::$steps is typed as array<int, Step>, not list<Step> — a hole in
// the keys is fine by the type. apply() always normalizes the array via
// array_values(), get() used to not do that and would index straight into
// the sparse array — the same index would then target a different step
// depending on which operation touched it.

$withHole = new Workflow(name: 'w', steps: [1 => $set('a'), 3 => $set('b')]);

Assert::same('a', StepTree::get($withHole, StepPath::parse('w.json:steps[0]'))->key);
Assert::same('b', StepTree::get($withHole, StepPath::parse('w.json:steps[1]'))->key);

// --- the workflow header stays untouched ---

$withHeader = new Workflow(
	name: 'w',
	inputs: ['a' => new Donut\Format\Input(name: 'a')],
	steps: [$set('a')],
	description: 'Description',
);

$after = StepTree::remove($withHeader, StepPath::parse('w.json:steps[0]'));
Assert::same('w', $after->name);
Assert::same('Description', $after->description);
Assert::same(['a'], \array_keys($after->inputs));
Assert::same([], $after->steps);
