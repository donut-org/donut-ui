<?php

declare(strict_types=1);

use Donut\Format\Input;
use Donut\Gui\InputMapper;
use Donut\Parser\BlockParser;
use Donut\Parser\WorkflowParser;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// --- round-trip over all inputs in the reference load ---
//
// 57 inputs: 36 in blocks, 21 in workflows. This is a richer load than what
// toInputs() had available inside BlockMapper — now it covers workflow
// inputs too.

$checked = 0;

$roundTrip = function (array $inputs) use (&$checked): void {
	if ($inputs === []) {
		return;
	}

	$again = InputMapper::toInputs(InputMapper::toValues($inputs));

	Assert::same(\serialize($inputs), \serialize($again));
	$checked += \count($inputs);
};

$blocks = \glob(__DIR__ . '/../../docs/workflows/donut/blocks/*.json');
Assert::count(15, $blocks === false ? [] : $blocks);

foreach ($blocks === false ? [] : $blocks as $file) {
	$roundTrip((new BlockParser)->parseFile($file)->inputs);
}

$workflows = \glob(__DIR__ . '/../../docs/workflows/donut/workflows/*.json');
Assert::count(4, $workflows === false ? [] : $workflows);

foreach ($workflows === false ? [] : $workflows as $file) {
	$roundTrip((new WorkflowParser)->parseFile($file)->inputs);
}

Assert::same(57, $checked, 'the reference load has 57 inputs');

// --- holes in the indexes get sorted out, ksort holds the order ---
//
// JS never renumbers rows, and the order of keys from POST isn't guaranteed.

$reversed = InputMapper::toInputs([
	3 => ['name' => 'zulu', 'required' => true, 'default' => '', 'description' => ''],
	0 => ['name' => 'alpha', 'required' => true, 'default' => '', 'description' => ''],
]);

Assert::same(['alpha', 'zulu'], \array_keys($reversed));

// --- a row without a name is an unfinished row, not an input ---

$withEmpty = InputMapper::toInputs([
	0 => ['name' => '', 'required' => true, 'default' => '', 'description' => 'nobody'],
	1 => ['name' => 'who', 'required' => true, 'default' => '', 'description' => ''],
]);

Assert::same(['who'], \array_keys($withEmpty));

// --- '' means unfilled ---

Assert::null($withEmpty['who']->default);
Assert::null($withEmpty['who']->description);

// --- required is read as bool; an unchecked checkbox doesn't appear in POST ---

$without = InputMapper::toInputs([0 => ['name' => 'a']]);
Assert::false($without['a']->required);

// --- toValues gives the shape the form expects ---

Assert::same(
	[
		['name' => 'url', 'required' => true, 'default' => '', 'description' => 'Address'],
		['name' => 'flag', 'required' => false, 'default' => 'x', 'description' => ''],
	],
	InputMapper::toValues([
		'url' => new Input(name: 'url', description: 'Address'),
		'flag' => new Input(name: 'flag', required: false, default: 'x'),
	]),
);

// An empty map gives an empty array, not a row with empty values.
Assert::same([], InputMapper::toValues([]));
