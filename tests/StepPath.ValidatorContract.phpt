<?php

declare(strict_types=1);

use Donut\BlockRepository;
use Donut\Gui\StepPath;
use Donut\Parser\WorkflowParser;
use Donut\Validator\Validator;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// Spojovací test smlouvy (I2): StepPath a Donut\Validator\Validator skládají
// tvar cesty ke kroku nezávisle na sobě, dřív to spojovaly jen dva stringové
// literály ve dvou nesouvisejících testech — donutím a tomhle tady. Když se
// rozejdou, tenhle test spadne; StepPath.phpt a
// tests/Donut/Validator.location.phpt samy o sobě to nepoznají.

$dir = TEMP_DIR . '/blocks';
FileSystem::createDir($dir);
$blocks = new BlockRepository($dir);
$parser = new WorkflowParser;
$validator = new Validator($blocks);

$name = 'w';

// Fixtura s if, else i foreach — proměří všechny tvary, které StepPath umí
// složit, naráz. Neplatná jména klíčů (pomlčka) dají problém přesně
// u kroku, který ho způsobil.
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
			'as' => 'radek',
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

// Fixtura pro problém, který nepatří žádnému kroku — nepoužitý vstup.
$workflowLevel = $parser->parseArray([
	'name' => $name,
	'inputs' => ['nepouzity' => []],
	'steps' => [],
], "{$name}.json");

Assert::same(
	[(string) StepPath::workflow($name)],
	\array_map(fn($problem) => $problem->location, $validator->validate($workflowLevel)->getProblems()),
);
