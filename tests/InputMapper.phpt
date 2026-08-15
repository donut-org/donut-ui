<?php

declare(strict_types=1);

use Donut\Format\Input;
use Donut\Gui\InputMapper;
use Donut\Parser\BlockParser;
use Donut\Parser\WorkflowParser;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// --- round-trip nad všemi vstupy referenční zátěže ---
//
// 57 vstupů: 36 u kamenů, 21 u workflow. Je to bohatší zátěž, než jakou
// mělo toInputs() uvnitř BlockMapperu k dispozici — teď zahrnuje i vstupy
// workflow.

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

Assert::same(57, $checked, 'referenční zátěž má 57 vstupů');

// --- díry v indexech se srovnají, pořadí drží ksort ---
//
// JS řádky nikdy nepřečísluje a pořadí klíčů z POSTu není zaručené.

$reversed = InputMapper::toInputs([
	3 => ['name' => 'zet', 'required' => true, 'default' => '', 'description' => ''],
	0 => ['name' => 'alfa', 'required' => true, 'default' => '', 'description' => ''],
]);

Assert::same(['alfa', 'zet'], \array_keys($reversed));

// --- řádek bez jména je nedopsaný řádek, ne vstup ---

$sPrazdnym = InputMapper::toInputs([
	0 => ['name' => '', 'required' => true, 'default' => '', 'description' => 'nikdo'],
	1 => ['name' => 'kdo', 'required' => true, 'default' => '', 'description' => ''],
]);

Assert::same(['kdo'], \array_keys($sPrazdnym));

// --- '' znamená nevyplněno ---

Assert::null($sPrazdnym['kdo']->default);
Assert::null($sPrazdnym['kdo']->description);

// --- required se čte jako bool; nezaškrtnuté políčko se v POSTu neobjeví ---

$bez = InputMapper::toInputs([0 => ['name' => 'a']]);
Assert::false($bez['a']->required);

// --- toValues dává tvar, který formulář očekává ---

Assert::same(
	[
		['name' => 'url', 'required' => true, 'default' => '', 'description' => 'Adresa'],
		['name' => 'flag', 'required' => false, 'default' => 'x', 'description' => ''],
	],
	InputMapper::toValues([
		'url' => new Input(name: 'url', description: 'Adresa'),
		'flag' => new Input(name: 'flag', required: false, default: 'x'),
	]),
);

// Prázdná mapa dá prázdné pole, ne řádek s prázdnými hodnotami.
Assert::same([], InputMapper::toValues([]));
