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
	'inputs' => ['input' => []],
	'steps' => [
		['type' => 'set', 'key' => 'fromSet', 'value' => '{%input%}'],
		[
			'type' => 'run', 'block' => 'echo',
			'in' => ['text' => '{%fromSet%} and {%input%}'],
			'out' => ['result' => 'fromOutput', 'exit_code' => 'code'],
		],
		[
			'type' => 'if',
			'condition' => ['left' => '{%code%}', 'op' => 'eq', 'right' => '{%input%}'],
			'then' => [['type' => 'set', 'key' => 'branch', 'value' => '{%fromOutput%}']],
			'else' => [['type' => 'set', 'key' => 'branch', 'value' => 'none']],
		],
		[
			'type' => 'foreach',
			'over' => '{%fromOutput%}',
			'as' => 'row',
			'steps' => [['type' => 'set', 'key' => 'inBody', 'value' => '{%row%}']],
		],
	],
], 'w.json');

$map = KeyMap::of($workflow);
$root = StepPath::root('w');

// --- what a specific step does ---

Assert::same(['fromSet'], $map->writesAt($root->index(0)));
Assert::same(['input'], $map->readsAt($root->index(0)));

// out has two channels → two written keys, alphabetically
Assert::same(['code', 'fromOutput'], $map->writesAt($root->index(1)));
// one template, two keys
Assert::same(['fromSet', 'input'], $map->readsAt($root->index(1)));

// if reads in both left and right and writes nothing itself
Assert::same(['code', 'input'], $map->readsAt($root->index(2)));
Assert::same([], $map->writesAt($root->index(2)));

// foreach writes its `as`
Assert::same(['row'], $map->writesAt($root->index(3)));
Assert::same(['fromOutput'], $map->readsAt($root->index(3)));

// a step that does nothing with keys gives empty arrays, not null
Assert::same([], $map->writesAt($root->index(9)));
Assert::same([], $map->readsAt($root->index(9)));

// --- nested steps ---

Assert::same(['branch'], $map->writesAt($root->index(2)->child('then')->index(0)));
Assert::same(['fromOutput'], $map->readsAt($root->index(2)->child('then')->index(0)));
Assert::same(['branch'], $map->writesAt($root->index(2)->child('else')->index(0)));
Assert::same([], $map->readsAt($root->index(2)->child('else')->index(0)));
Assert::same(['inBody'], $map->writesAt($root->index(3)->child('steps')->index(0)));

// --- everywhere a key appears ---

// branch is written in both branches
Assert::same(
	[
		(string) $root->index(2)->child('then')->index(0),
		(string) $root->index(2)->child('else')->index(0),
	],
	$map->writeSitesOf('branch'),
);

Assert::same(
	[(string) $root->index(0), (string) $root->index(1), (string) $root->index(2)],
	$map->readSitesOf('input'),
);

// nobody writes input — it's a workflow input
Assert::same([], $map->writeSitesOf('input'));

// a key that isn't in the workflow
Assert::same([], $map->writeSitesOf('unknown'));
Assert::same([], $map->readSitesOf('unknown'));

// --- key list ---

Assert::same(
	['branch', 'code', 'fromOutput', 'fromSet', 'inBody', 'input', 'row'],
	$map->keys(),
);

// --- highlight class ---

Assert::same('', $map->classAt($root->index(0), null));
Assert::same('write', $map->classAt($root->index(0), 'fromSet'));
Assert::same('read', $map->classAt($root->index(0), 'input'));
Assert::same('', $map->classAt($root->index(0), 'code'));

// a step that both reads and writes the same key gets both
$readsWrites = KeyMap::of($parser->parseArray([
	'name' => 'w',
	'inputs' => ['x' => []],
	'steps' => [['type' => 'set', 'key' => 'x', 'value' => '{%x%}']],
], 'w.json'));

Assert::same('write read', $readsWrites->classAt(StepPath::root('w')->index(0), 'x'));

// a path can also be passed as a string
Assert::same(['fromSet'], $map->writesAt((string) $root->index(0)));
Assert::same(['input'], $map->readsAt((string) $root->index(0)));
Assert::same('write', $map->classAt((string) $root->index(0), 'fromSet'));

// --- the same key more than once in one step ---

// two block inputs read the same key — the step is in the list only once
$dupRead = KeyMap::of($parser->parseArray([
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

Assert::same([(string) StepPath::root('w')->index(0)], $dupRead->readSitesOf('x'));

// two out channels map to the same key — the step is in the list only once
$dupWrite = KeyMap::of($parser->parseArray([
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

Assert::same([(string) StepPath::root('w')->index(0)], $dupWrite->writeSitesOf('y'));

// --- a key made up of digits only (I2) ---

// array_keys() would silently convert "456" to int — writesAt()/keys() must
// return a string, otherwise classAt() would never match against a string
// from the URL.
$number = KeyMap::of($parser->parseArray([
	'name' => 'w',
	'inputs' => [],
	'steps' => [['type' => 'set', 'key' => '456', 'value' => 'x']],
], 'w.json'));

Assert::same(['456'], $number->writesAt(StepPath::root('w')->index(0)));
Assert::same('write', $number->classAt(StepPath::root('w')->index(0), '456'));
Assert::same(['456'], $number->keys());
