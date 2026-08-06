<?php

declare(strict_types=1);

use Donut\Gui\KeyMap;
use Donut\Gui\StepPath;
use Donut\Parser\WorkflowParser;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$parser = new WorkflowParser;

$workflow = $parser->parseArray([
	'name' => 'w',
	'inputs' => ['vstup' => []],
	'steps' => [
		['type' => 'set', 'key' => 'zeSetu', 'value' => '{%vstup%}'],
		[
			'type' => 'run', 'block' => 'echo',
			'in' => ['text' => '{%zeSetu%} a {%vstup%}'],
			'out' => ['result' => 'zVystupu', 'exit_code' => 'kod'],
		],
		[
			'type' => 'if',
			'condition' => ['left' => '{%kod%}', 'op' => 'eq', 'right' => '{%vstup%}'],
			'then' => [['type' => 'set', 'key' => 'vetev', 'value' => '{%zVystupu%}']],
			'else' => [['type' => 'set', 'key' => 'vetev', 'value' => 'nic']],
		],
		[
			'type' => 'foreach',
			'over' => '{%zVystupu%}',
			'as' => 'radek',
			'steps' => [['type' => 'set', 'key' => 'vTele', 'value' => '{%radek%}']],
		],
	],
], 'w.json');

$map = KeyMap::of($workflow);
$root = StepPath::root('w');

// --- co dělá konkrétní krok ---

Assert::same(['zeSetu'], $map->writesAt($root->index(0)));
Assert::same(['vstup'], $map->readsAt($root->index(0)));

// out má dva kanály → dva zapsané klíče, abecedně
Assert::same(['kod', 'zVystupu'], $map->writesAt($root->index(1)));
// jedna šablona, dva klíče
Assert::same(['vstup', 'zeSetu'], $map->readsAt($root->index(1)));

// if čte v left i right a sám nic nezapisuje
Assert::same(['kod', 'vstup'], $map->readsAt($root->index(2)));
Assert::same([], $map->writesAt($root->index(2)));

// foreach zapisuje svoje `as`
Assert::same(['radek'], $map->writesAt($root->index(3)));
Assert::same(['zVystupu'], $map->readsAt($root->index(3)));

// krok, který s klíči nedělá nic, dá prázdná pole, ne null
Assert::same([], $map->writesAt($root->index(9)));
Assert::same([], $map->readsAt($root->index(9)));

// --- vnořené kroky ---

Assert::same(['vetev'], $map->writesAt($root->index(2)->child('then')->index(0)));
Assert::same(['zVystupu'], $map->readsAt($root->index(2)->child('then')->index(0)));
Assert::same(['vetev'], $map->writesAt($root->index(2)->child('else')->index(0)));
Assert::same([], $map->readsAt($root->index(2)->child('else')->index(0)));
Assert::same(['vTele'], $map->writesAt($root->index(3)->child('steps')->index(0)));

// --- kde všude klíč je ---

// vetev se zapisuje v obou větvích
Assert::same(
	[
		(string) $root->index(2)->child('then')->index(0),
		(string) $root->index(2)->child('else')->index(0),
	],
	$map->writeSitesOf('vetev'),
);

Assert::same(
	[(string) $root->index(0), (string) $root->index(1), (string) $root->index(2)],
	$map->readSitesOf('vstup'),
);

// vstup nikdo nezapisuje — je to vstup workflow
Assert::same([], $map->writeSitesOf('vstup'));

// klíč, který ve workflow není
Assert::same([], $map->writeSitesOf('neznamy'));
Assert::same([], $map->readSitesOf('neznamy'));

// --- seznam klíčů ---

Assert::same(
	['kod', 'radek', 'vTele', 'vetev', 'vstup', 'zVystupu', 'zeSetu'],
	$map->keys(),
);

// --- třída pro zvýraznění ---

Assert::same('', $map->classAt($root->index(0), null));
Assert::same('write', $map->classAt($root->index(0), 'zeSetu'));
Assert::same('read', $map->classAt($root->index(0), 'vstup'));
Assert::same('', $map->classAt($root->index(0), 'kod'));

// krok, který týž klíč čte i zapisuje, dostane obojí
$ctePise = KeyMap::of($parser->parseArray([
	'name' => 'w',
	'inputs' => ['x' => []],
	'steps' => [['type' => 'set', 'key' => 'x', 'value' => '{%x%}']],
], 'w.json'));

Assert::same('write read', $ctePise->classAt(StepPath::root('w')->index(0), 'x'));

// cesta jde předat i jako řetězec
Assert::same(['zeSetu'], $map->writesAt((string) $root->index(0)));
