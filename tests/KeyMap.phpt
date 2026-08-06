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
Assert::same(['vstup'], $map->readsAt((string) $root->index(0)));
Assert::same('write', $map->classAt((string) $root->index(0), 'zeSetu'));

// --- týž klíč vícekrát v jednom kroku ---

// dva vstupy kamene čtou tentýž klíč — krok je v seznamu jen jednou
$dupCteni = KeyMap::of($parser->parseArray([
	'name' => 'w',
	'inputs' => ['x' => []],
	'steps' => [
		[
			'type' => 'run', 'block' => 'echo',
			'in' => ['text' => '{%x%}', 'name' => '{%x%}'],
			'out' => [],
		],
	],
], 'w.json'));

Assert::same([(string) StepPath::root('w')->index(0)], $dupCteni->readSitesOf('x'));

// dva kanály out mapují na tentýž klíč — krok je v seznamu jen jednou
$dupZapis = KeyMap::of($parser->parseArray([
	'name' => 'w',
	'inputs' => [],
	'steps' => [
		[
			'type' => 'run', 'block' => 'echo',
			'in' => [],
			'out' => ['result' => 'y', 'stderr' => 'y'],
		],
	],
], 'w.json'));

Assert::same([(string) StepPath::root('w')->index(0)], $dupZapis->writeSitesOf('y'));

// --- klíč složený jen z číslic (I2) ---

// array_keys() by "456" tiše zkonvertovalo na int — writesAt()/keys() musí
// vracet string, jinak classAt() proti stringu z URL nikdy neuspěje.
$cislo = KeyMap::of($parser->parseArray([
	'name' => 'w',
	'inputs' => [],
	'steps' => [['type' => 'set', 'key' => '456', 'value' => 'x']],
], 'w.json'));

Assert::same(['456'], $cislo->writesAt(StepPath::root('w')->index(0)));
Assert::same('write', $cislo->classAt(StepPath::root('w')->index(0), '456'));
Assert::same(['456'], $cislo->keys());
