<?php

declare(strict_types=1);

use Donut\Format\Block;
use Donut\Format\Input;
use Donut\Format\RunStep;
use Donut\Format\StdinSpec;
use Donut\Gui\BlockInputs;
use Donut\Template;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// The inputs are deliberately NOT in alphabetical order: declaration order is
// filter, compact, while sorted order would be compact, filter. An
// implementation that sorted the slots instead of keeping the block's order
// would pass an alphabetical fixture without anyone noticing.
$jq = new Block(
	name: 'jq',
	command: 'jq',
	args: [['{%filter%}'], ['{%compact%}']],
	inputs: [
		'filter' => new Input('filter', required: true, description: 'jq expression'),
		'compact' => new Input('compact', required: true, default: '-c'),
	],
	stdin: new StdinSpec(required: true, description: 'JSON to filter'),
);

// --- a new step: every slot is empty, order comes from the block ---

$slots = BlockInputs::slots($jq, null);

Assert::same(
	['stdin', 'filter', 'compact'],
	array_map(fn($slot): string => $slot->name, $slots),
	'stdin first, then declaration order — not alphabetical'
);
Assert::same(['', '', ''], array_map(fn($slot): string => $slot->value, $slots));
Assert::same([true, true, true], array_map(fn($slot): bool => $slot->declared, $slots));

// --- an existing step: values land in their slots ---

$step = new RunStep(
	block: 'jq',
	in: [
		'filter' => Template::parse('.id'),
		'stdin' => Template::parse('{%body%}'),
		// A key the block does not declare — a typo, or an input that
		// disappeared from the block after the workflow was written.
		'filtr' => Template::parse('.old'),
	],
);

$slots = BlockInputs::slots($jq, $step);

Assert::same(
	['stdin', 'filter', 'compact', 'filtr'],
	array_map(fn($slot): string => $slot->name, $slots),
	'stdin first, undeclared keys last in the order the step has them'
);
Assert::same(['{%body%}', '.id', '', '.old'], array_map(fn($slot): string => $slot->value, $slots));
Assert::same([true, true, true, false], array_map(fn($slot): bool => $slot->declared, $slots));

// required: stdin follows stdin.required; "filter" is required and has no
// default; "compact" is required but has one, so it is never missing; an
// undeclared key is never required — it has to be emptied.
Assert::same([true, true, false, false], array_map(fn($slot): bool => $slot->required, $slots));

// the declaration travels with the slot, so the form can show it
Assert::same([null, null, '-c', null], array_map(fn($slot): ?string => $slot->default, $slots));
Assert::same(
	['JSON to filter', 'jq expression', null, null],
	array_map(fn($slot): ?string => $slot->description, $slots)
);

// --- a block with no inputs and no stdin has no slots ---

$echo = new Block(name: 'echo', command: 'echo', args: [['hi']]);
Assert::same([], BlockInputs::slots($echo, null));

// --- a block that does not read stdin gets no stdin slot ---

$noStdin = new Block(
	name: 'greet',
	command: 'echo',
	args: [['{%text%}']],
	inputs: ['text' => new Input('text')],
);
Assert::same(['text'], array_map(fn($slot): string => $slot->name, BlockInputs::slots($noStdin, null)));

// --- a block that declares an input named stdin gets one slot, not two ---
// The validator reports that as an error; the form must not render the same
// name twice, because the second field would overwrite the first on save.

$clash = new Block(
	name: 'clash',
	command: 'cat',
	args: [['{%stdin%}']],
	inputs: ['stdin' => new Input('stdin')],
	stdin: new StdinSpec,
);
Assert::same(['stdin'], array_map(fn($slot): string => $slot->name, BlockInputs::slots($clash, null)));

// --- rows(): the POST is joined back with the names by position ---

$slots = BlockInputs::slots($jq, null);

Assert::same(
	[
		['key' => 'stdin', 'value' => '{%body%}'],
		['key' => 'filter', 'value' => '.id'],
	],
	BlockInputs::rows($slots, [
		0 => ['value' => '{%body%}'],
		1 => ['value' => '.id'],
		2 => ['value' => ''],
	]),
	'index 2 is "compact" and it is empty, so it is not written at all'
);

// An empty value must not become an empty template. A key present with an
// empty string suppresses the block's default (CommandLine::resolveValues()),
// while a missing key lets the default through — so the form never writes one.
Assert::same([], BlockInputs::rows($slots, [0 => ['value' => '   ']]), 'whitespace only is empty');
Assert::same([], BlockInputs::rows($slots, []));
Assert::same([], BlockInputs::rows($slots, null), 'no in container in the POST at all');
Assert::same([], BlockInputs::rows([], [0 => ['value' => 'x']]), 'a value with no slot has no name');
