<?php

declare(strict_types=1);

use Donut\Format\Condition;
use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\RunStep;
use Donut\Format\SetStep;
use Donut\Gui\StepMapper;
use Donut\Parser\WorkflowParser;
use Donut\Template;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// --- round-trip over the reference load ---
//
// 96 steps: 75 run, 9 set, 5 if, 7 foreach. If the mapper dropped timeout,
// allow_failure or name, this would catch it.

$parser = new WorkflowParser;
$files = \glob(__DIR__ . '/../../docs/workflows/donut/workflows/*.json');
Assert::count(4, $files === false ? [] : $files);

$checked = 0;

// IfStep and ForeachStep carry nested steps that toValues() doesn't put into
// the form values — the step page doesn't edit branches, those are filled in
// from the overview. toStep() therefore returns a step with empty branches,
// and for these the round-trip must be compared against a step stripped of
// its children.
$bare = function (Donut\Format\Step $step): Donut\Format\Step {
	if ($step instanceof IfStep) {
		return new IfStep($step->condition, [], [], $step->name);
	}

	if ($step instanceof ForeachStep) {
		return new ForeachStep($step->over, $step->as, [], $step->name);
	}

	return $step;
};

$walk = function (array $steps) use (&$walk, &$checked, $bare): void {
	foreach ($steps as $step) {
		$checked++;

		Assert::same(
			\serialize($bare($step)),
			\serialize(StepMapper::toStep(StepMapper::toValues($step))),
			'round-trip ' . $step::class,
		);

		if ($step instanceof IfStep) {
			$walk($step->then);
			$walk($step->else);

		} elseif ($step instanceof ForeachStep) {
			$walk($step->steps);
		}
	}
};

foreach ($files === false ? [] : $files as $file) {
	$walk($parser->parseFile($file)->steps);
}

Assert::same(96, $checked, 'the reference load has 96 steps');

// --- run: all optional fields ---
$run = StepMapper::toStep([
	'type' => 'run',
	'name' => 'named-step',
	'block' => 'jq',
	// Holes in the indexes on purpose — JS never renumbers rows.
	'in' => [
		0 => ['key' => 'stdin', 'value' => '{%payload%}'],
		2 => ['key' => 'filter', 'value' => '.id'],
	],
	'out' => [1 => ['channel' => 'result', 'value' => 'cardId']],
	'timeout' => '90',
	'allowFailure' => 'list',
	'allowFailureCodes' => '0, 1',
]);

Assert::type(RunStep::class, $run);
Assert::same('jq', $run->block);
Assert::same('named-step', $run->name);
Assert::same(['stdin', 'filter'], \array_keys($run->in));
Assert::same('{%payload%}', $run->in['stdin']->getSource());
Assert::same(['result' => 'cardId'], $run->out);
Assert::same(90, $run->timeout);
Assert::same([0, 1], $run->allowFailure);

// --- run: the four allow_failure states ---

$base = ['type' => 'run', 'name' => '', 'block' => 'echo', 'in' => [], 'out' => [],
	'timeout' => '', 'allowFailure' => 'inherit', 'allowFailureCodes' => ''];

Assert::null(StepMapper::toStep($base)->allowFailure);
Assert::false(StepMapper::toStep(['allowFailure' => 'none'] + $base)->allowFailure);
Assert::true(StepMapper::toStep(['allowFailure' => 'any'] + $base)->allowFailure);
Assert::same([2], StepMapper::toStep(['allowFailure' => 'list', 'allowFailureCodes' => '2, x'] + $base)->allowFailure);

// An empty list under 'list' falls back to inherit — the parser would reject [].
Assert::null(StepMapper::toStep(['allowFailure' => 'list'] + $base)->allowFailure);

// --- empty rows drop out ---

$withEmpty = StepMapper::toStep([
	// Row 2: key filled in, value not — unlike out (see below) this isn't
	// dropped, it just gets an empty template. A key without a value is a
	// valid input, format spec section 6: an empty string and unfilled are
	// the same thing.
	'in' => [0 => ['key' => '', 'value' => 'nowhere'], 1 => ['key' => 'a', 'value' => 'x'], 2 => ['key' => 'b', 'value' => '']],
	'out' => [0 => ['channel' => 'result', 'value' => '']],
] + $base);

Assert::same(['a', 'b'], \array_keys($withEmpty->in));
Assert::same('', $withEmpty->in['b']->getSource(), 'a filled-in key with an empty value is not dropped');
Assert::same([], $withEmpty->out);

// --- the order of keys from POST isn't guaranteed, and order matters for both in and out ---
//
// Without ksort() in rows(), a descending key order would show up as a
// reversed row order — the index hole tested elsewhere in the file is
// ascending, so it wouldn't catch this mutation.

$reversed = StepMapper::toStep([
	'in' => [
		1 => ['key' => 'second', 'value' => 'b'],
		0 => ['key' => 'first', 'value' => 'a'],
	],
	'out' => [
		1 => ['channel' => 'stderr', 'value' => 'err'],
		0 => ['channel' => 'result', 'value' => 'res'],
	],
] + $base);

Assert::same(['first', 'second'], \array_keys($reversed->in));
Assert::same(['result' => 'res', 'stderr' => 'err'], $reversed->out);

// --- '' means unfilled ---

Assert::null(StepMapper::toStep($base)->name);
Assert::null(StepMapper::toStep($base)->timeout);

// --- set ---

$set = StepMapper::toStep(['type' => 'set', 'name' => 'the-name', 'key' => 'branch', 'value' => 'f/{%id%}']);
Assert::type(SetStep::class, $set);
Assert::same('branch', $set->key);
Assert::same('f/{%id%}', $set->value->getSource());
Assert::same('the-name', $set->name);

// --- if, including the unary operator ---

$if = StepMapper::toStep(['type' => 'if', 'name' => '', 'left' => '{%a%}', 'op' => 'eq', 'right' => '{%b%}']);
Assert::type(IfStep::class, $if);
Assert::same('{%b%}', $if->condition->right?->getSource());
Assert::same([], $if->then);
Assert::same([], $if->else);

// For unary operators the right side is discarded, even if something was left in the form.
$unary = StepMapper::toStep(['type' => 'if', 'name' => '', 'left' => '{%a%}', 'op' => 'not_empty', 'right' => 'leftover']);
Assert::null($unary->condition->right);

// --- foreach ---

$foreach = StepMapper::toStep(['type' => 'foreach', 'name' => '', 'over' => '{%cards%}', 'as' => 'card']);
Assert::type(ForeachStep::class, $foreach);
Assert::same('{%cards%}', $foreach->over->getSource());
Assert::same('card', $foreach->as);
Assert::same([], $foreach->steps);

// --- unknown type ---

Assert::exception(
	fn() => StepMapper::toStep(['type' => 'nonsense']),
	InvalidArgumentException::class,
);

// --- toValues gives the shape the form expects ---

$values = StepMapper::toValues(new RunStep(
	block: 'jq',
	in: ['stdin' => Template::parse('{%p%}')],
	out: ['result' => 'id'],
	timeout: 30,
	allowFailure: [0, 1],
	name: 'the-name',
));

Assert::same('run', $values['type']);
Assert::same('jq', $values['block']);
Assert::same([['key' => 'stdin', 'value' => '{%p%}']], $values['in']);
Assert::same([['channel' => 'result', 'value' => 'id']], $values['out']);
Assert::same('30', $values['timeout']);
Assert::same('list', $values['allowFailure']);
Assert::same('0, 1', $values['allowFailureCodes']);

// Unfilled fields come out as '' and inherit, not as null.
$bare2 = StepMapper::toValues(new RunStep(block: 'echo'));
Assert::same('', $bare2['name']);
Assert::same('', $bare2['timeout']);
Assert::same('inherit', $bare2['allowFailure']);

// --- keepChildren: editing must not delete the subtree ---

$branched = new IfStep(
	condition: new Condition(left: Template::parse('{%a%}'), op: 'not_empty'),
	then: [new SetStep(key: 't', value: Template::parse('1'))],
	else: [new SetStep(key: 'e', value: Template::parse('2'))],
	name: 'original',
);

$updated = StepMapper::keepChildren(
	$branched,
	new IfStep(
		condition: new Condition(left: Template::parse('{%b%}'), op: 'empty'),
		name: 'new',
	),
);

Assert::type(IfStep::class, $updated);
Assert::same('{%b%}', $updated->condition->left->getSource(), 'the condition should be taken from the new step');
Assert::same('new', $updated->name);
Assert::count(1, $updated->then, 'the then branch must not be lost');
Assert::count(1, $updated->else, 'the else branch must not be lost');
Assert::same('t', $updated->then[0]->key);

// The same for foreach.
$body = new ForeachStep(
	over: Template::parse('{%x%}'),
	as: 'a',
	steps: [new SetStep(key: 's', value: Template::parse('1'))],
);

$updatedForeach = StepMapper::keepChildren(
	$body,
	new ForeachStep(over: Template::parse('{%y%}'), as: 'b'),
);

Assert::same('{%y%}', $updatedForeach->over->getSource());
Assert::same('b', $updatedForeach->as);
Assert::count(1, $updatedForeach->steps, 'the foreach body must not be lost');

// A mismatched type: the new step is returned unchanged, branches aren't carried over.
$different = StepMapper::keepChildren($branched, new SetStep(key: 'k', value: Template::parse('1')));
Assert::type(SetStep::class, $different);
