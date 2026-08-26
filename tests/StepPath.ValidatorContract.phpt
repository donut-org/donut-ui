<?php

declare(strict_types=1);

use Donut\BlockRepository;
use Donut\Gui\StepPath;
use Donut\Parser\WorkflowParser;
use Donut\Validator\Validator;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// A contract test connecting the two (I2): StepPath and
// Donut\Validator\Validator assemble the step path shape independently of
// each other; before this, only two string literals in two unrelated
// tests — donut's and this one — connected them. If they drift apart, this
// test fails; StepPath.phpt and tests/Donut/Validator.location.phpt
// wouldn't notice on their own.

$dir = TEMP_DIR . '/blocks';
FileSystem::createDir($dir);
$blocks = new BlockRepository($dir);
$parser = new WorkflowParser;
$validator = new Validator($blocks);

$name = 'w';

// A fixture with if, else and foreach — exercises every shape StepPath can
// assemble in one go. Invalid key names (a hyphen) produce a problem at
// exactly the step that caused it.
$workflow = $parser->parseArray([
	'name' => $name,
	'inputs' => ['t' => []],
	'steps' => [
		['type' => 'set', 'key' => 'A-B', 'value' => 'x'],
		[
			'type' => 'if',
			'condition' => ['left' => '{%t%}', 'op' => 'not_empty'],
			'then' => [['type' => 'set', 'key' => 'C-D', 'value' => 'x']],
			'else' => [['type' => 'set', 'key' => 'E-F', 'value' => 'x']],
		],
		[
			'type' => 'foreach',
			'over' => '{%t%}',
			'as' => 'row',
			'steps' => [['type' => 'set', 'key' => 'G-H', 'value' => 'x']],
		],
	],
], "{$name}.json");

$locations = \array_map(
	fn($problem) => $problem->location,
	$validator->validate($workflow)->getProblems(),
);

Assert::contains((string) StepPath::root($name)->index(0), $locations);
Assert::contains((string) StepPath::root($name)->index(1)->child('then')->index(0), $locations);
Assert::contains((string) StepPath::root($name)->index(1)->child('else')->index(0), $locations);
Assert::contains((string) StepPath::root($name)->index(2)->child('steps')->index(0), $locations);

// A fixture for a problem that doesn't belong to any step — an unused input.
$workflowLevel = $parser->parseArray([
	'name' => $name,
	'inputs' => ['unused' => []],
	'steps' => [],
], "{$name}.json");

Assert::same(
	[(string) StepPath::workflow($name)],
	\array_map(fn($problem) => $problem->location, $validator->validate($workflowLevel)->getProblems()),
);
