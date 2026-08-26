<?php

declare(strict_types=1);

use Donut\Format\Condition;
use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\RunStep;
use Donut\Format\SetStep;
use Donut\Format\Step;
use Donut\Format\Workflow;
use Donut\Gui\BlockUsage;
use Donut\Template;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// Blocks hidden at all three nesting levels: directly in steps,
// in the then branch, in the else branch, and inside a foreach.
$w1 = new Workflow(name: 'first', steps: [
	new RunStep(block: 'echo'),
	new IfStep(
		condition: new Condition(left: Template::parse('{%a%}'), op: 'not_empty'),
		then: [new RunStep(block: 'jq')],
		else: [new ForeachStep(
			over: Template::parse('{%list%}'),
			as: 'row',
			steps: [new RunStep(block: 'curl-get')],
		)],
	),
	new SetStep(key: 'x', value: Template::parse('1')),
]);

$w2 = new Workflow(name: 'second', steps: [
	new RunStep(block: 'echo'),
	new RunStep(block: 'echo'),
]);

$usage = BlockUsage::of(['second' => $w2, 'first' => $w1]);

// A block used in both workflows is listed once per workflow, not once per step.
Assert::same(['first', 'second'], $usage['echo']);

// A block in a nested branch is found.
Assert::same(['first'], $usage['jq']);
Assert::same(['first'], $usage['curl-get']);

// The outer map is sorted by block name, not by order of appearance.
Assert::same(['curl-get', 'echo', 'jq'], \array_keys($usage));

// An unused block isn't in the map at all.
Assert::false(\array_key_exists('fail', $usage));

// An empty input gives an empty map, not an error.
Assert::same([], BlockUsage::of([]));

// A workflow without a single run step too.
Assert::same([], BlockUsage::of(['x' => new Workflow(name: 'x')]));

// A block name and a workflow name may both be purely numeric (no format
// rule forbids it) — PHP would silently convert such an array key to int.
// Values inside the inner list must stay strings even for this input.
$numbers = BlockUsage::of(['123' => new Workflow(name: '123', steps: [new RunStep(block: '456')])]);
Assert::same(['123'], $numbers['456']);

// An unknown step type must throw — this map is the check before deleting
// blocks, and a silent fall-through would let deletion through a block that
// some workflow still uses (see the comment on walk()).
Assert::exception(
	fn() => BlockUsage::of(['x' => new Workflow(name: 'x', steps: [new class implements Step {}])]),
	LogicException::class,
);
