<?php

declare(strict_types=1);

use Donut\BlockRepository;
use Donut\Gui\KeyMap;
use Donut\Parser\WorkflowParser;
use Donut\Validator\Validator;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// A contract test connecting the two: KeyMap and Donut\Validator\Validator
// compute the same key sets via two independent walks of the tree.
// Neither KeyMap.phpt nor donut's own tests would notice a drift on their
// own — this one does.
//
// Runs over the reference workload, because it has all four step types, both
// if branches, and a foreach, and donut's acceptance test guards it at 0
// errors and 0 warnings. docs/workflows/donut/ is test data here, not code —
// the rule "the GUI must not reach outside itself" is about gui/src, not
// about fixtures in tests.

$root = __DIR__ . '/../../docs/workflows/donut';

$blocks = new BlockRepository($root . '/blocks');
$parser = new WorkflowParser;
$validator = new Validator($blocks);

// glob() returns list<string>|false — false only when the pattern itself is
// invalid, which can't happen here, but PHPStan (level: max) doesn't know that.
$files = \glob($root . '/workflows/*.json');
Assert::true(\is_array($files), 'glob() over the reference workload must not fail');
$files = \is_array($files) ? $files : [];

Assert::count(4, $files, 'the reference workload has four workflows');

foreach ($files as $file) {
	$workflow = $parser->parseFile($file);
	$result = $validator->validate($workflow);
	$map = KeyMap::of($workflow);

	// Compare written and read keys separately — a union would hide a key
	// recorded in the wrong direction
	$guiWritten = \array_values(\array_filter($map->keys(), fn($k) => $map->writeSitesOf($k) !== []));
	$guiRead = \array_values(\array_filter($map->keys(), fn($k) => $map->readSitesOf($k) !== []));

	Assert::same(
		$result->getWrittenKeys(),
		$guiWritten,
		"written keys in {$workflow->name} must match the validator",
	);

	Assert::same(
		$result->getReadKeys(),
		$guiRead,
		"read keys in {$workflow->name} must match the validator",
	);
}

// A fixture of our own, alongside the reference workload: none of the four
// reference workflows has a key that occurs only inside then, only inside
// else, or only in a foreach body — so discarding a whole subtree in
// walk() would leave the comparison above green (both sides would equally
// miss nothing, because the Validator finds those keys and KeyMap would
// silently not record them at all). This fixture has, in each of those
// three branches, a key that appears nowhere else, so a drift is visible.
$dir = TEMP_DIR . '/keymap-validator-contract';
FileSystem::createDir($dir);
$branchBlocks = new BlockRepository($dir);
$branchWorkflow = $parser->parseArray([
	'name' => 'w',
	'inputs' => ['t' => []],
	'steps' => [
		[
			'type' => 'if',
			'condition' => ['left' => '{%t%}', 'op' => 'not_empty'],
			'then' => [['type' => 'set', 'key' => 'onlyInThen', 'value' => 'x']],
			'else' => [['type' => 'set', 'key' => 'onlyInElse', 'value' => 'x']],
		],
		[
			'type' => 'foreach',
			'over' => '{%t%}',
			'as' => 'row',
			'steps' => [['type' => 'set', 'key' => 'onlyInForeach', 'value' => '{%row%}']],
		],
	],
], 'w.json');

$branchResult = (new Validator($branchBlocks))->validate($branchWorkflow);
$branchMap = KeyMap::of($branchWorkflow);

$branchGuiWritten = \array_values(\array_filter($branchMap->keys(), fn($k) => $branchMap->writeSitesOf($k) !== []));
$branchGuiRead = \array_values(\array_filter($branchMap->keys(), fn($k) => $branchMap->readSitesOf($k) !== []));

Assert::same(['onlyInElse', 'onlyInForeach', 'onlyInThen', 'row'], $branchGuiWritten);
Assert::same($branchResult->getWrittenKeys(), $branchGuiWritten);
Assert::same($branchResult->getReadKeys(), $branchGuiRead);
