<?php

declare(strict_types=1);

use Donut\BlockRepository;
use Donut\Gui\KeyMap;
use Donut\Parser\WorkflowParser;
use Donut\Validator\Validator;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// Spojovací test smlouvy: KeyMap a Donut\Validator\Validator počítají tytéž
// množiny klíčů dvěma nezávislými průchody stromem. KeyMap.phpt ani donutí
// testy samy o sobě rozchod nepoznají — tenhle ano.
//
// Jede nad referenční zátěží, protože ta má všechny čtyři typy kroků, obě
// větve if i foreach, a hlídá ji přijímací test donutu na 0 chyb a 0 varování.
// docs/workflows/donut/ je tu testovací data, ne kód — pravidlo „GUI nesmí
// sáhnout mimo sebe" mluví o gui/src, ne o fixturách v testech.

$root = __DIR__ . '/../../docs/workflows/donut';

$blocks = new BlockRepository($root . '/blocks');
$parser = new WorkflowParser;
$validator = new Validator($blocks);

// glob() vrací list<string>|false — false jen když je vzor sám nevalidní,
// což se tady nemůže stát, ale PHPStan (level: max) to neví.
$files = \glob($root . '/workflows/*.json');
Assert::true(\is_array($files), 'glob() nad referenční zátěží nesmí selhat');
$files = \is_array($files) ? $files : [];

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

// Vlastní fixtura vedle referenční zátěže: v žádném ze čtyř referenčních
// workflow neexistuje klíč, který by se vyskytoval jen uvnitř then, jen
// uvnitř else, nebo jen v těle foreach — takže zahození celého podstromu ve
// walk() nechá porovnání nahoře zelené (chybí na obou stranách stejně
// nic, protože Validator ty klíče najde a KeyMap by je celé mlčky
// nezaznamenal). Tahle fixtura má v každé z těch tří větví klíč, který se
// nikde jinde neobjevuje, takže rozchod je vidět.
$dir = TEMP_DIR . '/keymap-validator-contract';
FileSystem::createDir($dir);
$vetveBlocks = new BlockRepository($dir);
$vetveWorkflow = $parser->parseArray([
	'name' => 'w',
	'inputs' => ['t' => []],
	'steps' => [
		[
			'type' => 'if',
			'condition' => ['left' => '{%t%}', 'op' => 'not_empty'],
			'then' => [['type' => 'set', 'key' => 'jenVThen', 'value' => 'x']],
			'else' => [['type' => 'set', 'key' => 'jenVElse', 'value' => 'x']],
		],
		[
			'type' => 'foreach',
			'over' => '{%t%}',
			'as' => 'radek',
			'steps' => [['type' => 'set', 'key' => 'jenVForeach', 'value' => '{%radek%}']],
		],
	],
], 'w.json');

$vetveResult = (new Validator($vetveBlocks))->validate($vetveWorkflow);
$vetveMap = KeyMap::of($vetveWorkflow);

$vetveGuiWritten = \array_values(\array_filter($vetveMap->keys(), fn($k) => $vetveMap->writeSitesOf($k) !== []));
$vetveGuiRead = \array_values(\array_filter($vetveMap->keys(), fn($k) => $vetveMap->readSitesOf($k) !== []));

Assert::same(['jenVElse', 'jenVForeach', 'jenVThen', 'radek'], $vetveGuiWritten);
Assert::same($vetveResult->getWrittenKeys(), $vetveGuiWritten);
Assert::same($vetveResult->getReadKeys(), $vetveGuiRead);
