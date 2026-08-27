<?php

declare(strict_types=1);

use Donut\BlockRepository;
use Donut\Format\Condition;
use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\RunStep;
use Donut\Format\SetStep;
use Donut\Gui\BlockInputs;
use Donut\Gui\StepMapper;
use Donut\Parser\WorkflowParser;
use Donut\Template;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// --- round-trip over the reference workload ---
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
//
// A RunStep's `in` is the same kind of omission: toValues() deliberately
// stops building the in rows, because the container they belong to is keyed
// by position now and its names come from the block (BlockInputs::slots()),
// not from this method. So the round-trip is compared against a step
// stripped of its inputs. The two directions are asserted apart from each
// other: that toValues() really omits `in` under "toValues gives the shape
// the form expects" further down, and that toStep() still reads in rows
// under "run: all optional fields" and "the order of keys from POST isn't
// guaranteed" above it.
$bare = function (Donut\Format\Step $step): Donut\Format\Step {
	if ($step instanceof IfStep) {
		return new IfStep($step->condition, [], [], $step->name);
	}

	if ($step instanceof ForeachStep) {
		return new ForeachStep($step->over, $step->as, [], $step->name);
	}

	if ($step instanceof RunStep) {
		return new RunStep(
			$step->block,
			[],
			$step->out,
			$step->timeout,
			$step->allowFailure,
			$step->name,
		);
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

Assert::same(96, $checked, 'the reference workload has 96 steps');

// --- the reference workload through the slots, not just through the mapper ---
//
// toValues() no longer carries `in`, so $bare() strips it from the round trip
// above — and with it the only place `in` met the whole corpus. That breadth
// is restored here: every real run step's inputs go out through
// BlockInputs::slots() and come back through rows(), the way the form moves
// them. Two things this buys that the hand-written fixtures
// cannot: it is the only whole-corpus proof that opening and re-saving an
// existing workflow through the new form is lossless, and it fails loudly the
// day a block stops declaring an input that a workflow still passes it.

$blocks = new BlockRepository(__DIR__ . '/../../docs/workflows/donut/blocks');
$runs = 0;

$walkIn = function (array $steps) use (&$walkIn, $blocks, &$runs): void {
	foreach ($steps as $step) {
		if ($step instanceof RunStep) {
			$runs++;
			$slots = BlockInputs::slots($blocks->get($step->block), $step);
			$post = [];

			foreach ($slots as $i => $slot) {
				$post[$i] = ['value' => $slot->value];
			}

			$rebuilt = StepMapper::toStep([
				'type' => 'run', 'name' => '', 'block' => $step->block,
				'in' => BlockInputs::rows($slots, $post),
				'out' => [], 'timeout' => '',
				'allowFailure' => 'inherit', 'allowFailureCodes' => '',
			]);

			Assert::same(
				\serialize($step->in),
				\serialize($rebuilt->in),
				"in round-trip through the slots, block {$step->block}"
			);
		}

		if ($step instanceof IfStep) {
			$walkIn($step->then);
			$walkIn($step->else);
		}

		if ($step instanceof ForeachStep) {
			$walkIn($step->steps);
		}
	}
};

foreach ($files === false ? [] : $files as $file) {
	$walkIn($parser->parseFile($file)->steps);
}

// The guard that makes the walk fail loudly if the corpus stops being walked
// at all — 75 of the 96 steps above are run steps.
Assert::same(75, $runs, 'the reference workload has 75 run steps');

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
	'out' => ['stdout' => 'cardId'],
	'timeout' => '90',
	'allowFailure' => 'list',
	'allowFailureCodes' => '0, 1',
]);

Assert::type(RunStep::class, $run);
Assert::same('jq', $run->block);
Assert::same('named-step', $run->name);
Assert::same(['stdin', 'filter'], \array_keys($run->in));
Assert::same('{%payload%}', $run->in['stdin']->getSource());
Assert::same(['stdout' => 'cardId'], $run->out);
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
	'out' => ['stdout' => ''],
] + $base);

Assert::same(['a', 'b'], \array_keys($withEmpty->in));
Assert::same('', $withEmpty->in['b']->getSource(), 'a filled-in key with an empty value is not dropped');
Assert::same([], $withEmpty->out);

// --- out: three named fields, not rows ---
//
// An empty field means the channel is not mapped; there is no such thing as
// a row with a channel and no key.
$run2 = StepMapper::toStep([
	'type' => 'run', 'name' => '', 'block' => 'jq',
	'in' => [],
	'out' => ['stdout' => 'cardId', 'stderr' => '', 'exit_code' => 'rc'],
	'timeout' => '', 'allowFailure' => 'inherit', 'allowFailureCodes' => '',
]);

Assert::type(RunStep::class, $run2);
Assert::same(['stdout' => 'cardId', 'exit_code' => 'rc'], $run2->out, 'an empty field is not a mapping');

// a channel the form cannot offer is ignored — the fields are built from
// RunStep::Channels, so anything else came from a hand-built POST
$forged = StepMapper::toStep([
	'type' => 'run', 'name' => '', 'block' => 'jq',
	'in' => [], 'out' => ['result' => 'x', 'stdout' => 'ok'],
	'timeout' => '', 'allowFailure' => 'inherit', 'allowFailureCodes' => '',
]);

Assert::same(['stdout' => 'ok'], $forged->out);

// and back: every channel is present, unmapped ones as an empty string, so
// setDefaults() has something to put in each of the three fields
$roundTrip = StepMapper::toValues(new RunStep(block: 'jq', out: ['stdout' => 'id']));

Assert::same(['stdout' => 'id', 'stderr' => '', 'exit_code' => ''], $roundTrip['out']);

// --- the order of keys from POST isn't guaranteed, and order matters for in ---
//
// Without ksort() in rows(), a descending key order would show up as a
// reversed row order — the index hole tested elsewhere in the file is
// ascending, so it wouldn't catch this mutation.

$reversed = StepMapper::toStep([
	'in' => [
		1 => ['key' => 'second', 'value' => 'b'],
		0 => ['key' => 'first', 'value' => 'a'],
	],
	// out is looked up by channel name now, not by position — its raw key
	// order can't affect anything, scrambled here on purpose.
	'out' => ['exit_code' => 'ec', 'stdout' => 'res', 'stderr' => 'err'],
] + $base);

Assert::same(['first', 'second'], \array_keys($reversed->in));
Assert::same(['stdout' => 'res', 'stderr' => 'err', 'exit_code' => 'ec'], $reversed->out, 'out is keyed by channel, its raw order is irrelevant');

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
	out: ['stdout' => 'id'],
	timeout: 30,
	allowFailure: [0, 1],
	name: 'the-name',
));

Assert::same('run', $values['type']);
Assert::same('jq', $values['block']);
// `in` is not among the values any more: the inputs travel with the slots,
// see BlockInputs. Asserting its absence is what keeps a half-finished
// revert from passing.
Assert::false(array_key_exists('in', $values), 'toValues() does not build the in rows');
Assert::same(['stdout' => 'id', 'stderr' => '', 'exit_code' => ''], $values['out']);
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
