<?php

declare(strict_types=1);

use Donut\BlockRepository;
use Donut\Gui\KeyMap;
use Donut\Parser\WorkflowParser;
use Donut\Validator\Validator;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// Spojovací test smlouvy: KeyMap a Donut\Validator\Validator počítají tytéž
// množiny klíčů dvěma nezávislými průchody stromem. KeyMap.phpt ani donutí
// testy samy o sobě rozchod nepoznají — tenhle ano.
//
// Jede nad referenční zátěží, protože ta má všechny čtyři typy kroků, obě
// větve if i foreach, a hlídá ji přijímací test donutu na 0 chyb a 0 varování.

$root = __DIR__ . '/../../docs/workflows/donut';

$blocks = new BlockRepository($root . '/blocks');
$parser = new WorkflowParser;
$validator = new Validator($blocks);

$files = \glob($root . '/workflows/*.json');
Assert::count(4, $files, 'referenční zátěž má čtyři workflow');

foreach ($files as $file) {
	$workflow = $parser->parseFile($file);
	$result = $validator->validate($workflow);
	$map = KeyMap::of($workflow);

	// Porovnat zvlášť zapsané a přečtené klíče — unifikace by skryla
	// klíče zaznamenané v špatném směru
	$guiWritten = \array_values(\array_filter($map->keys(), fn($k) => $map->writeSitesOf($k) !== []));
	$guiRead = \array_values(\array_filter($map->keys(), fn($k) => $map->readSitesOf($k) !== []));

	Assert::same(
		$result->getWrittenKeys(),
		$guiWritten,
		"zapsané klíče v {$workflow->name} se musí shodovat s validátorem",
	);

	Assert::same(
		$result->getReadKeys(),
		$guiRead,
		"přečtené klíče v {$workflow->name} se musí shodovat s validátorem",
	);
}
