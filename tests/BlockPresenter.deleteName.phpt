<?php

declare(strict_types=1);

use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/blockPresenter.php';

// N7: jméno ze skrytého pole prochází basename(), stejně jako u workflow.
// Sám soubor je bezpečný i bez toho (BlockStore::exists() se ptá do mapy
// klíčované basename($path, '.json'), takže jméno s lomítkem v ní nemůže být
// klíčem), ale kontrola použití porovnává jméno z požadavku přímo — a bez
// basename() na podvrženou cestu nesedne a rozhodne o mazání až vzdálená
// implementace úložiště.

$project = TEMP_DIR . '/delete-name';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');

// pouzity je v workflow, volny ne — mazací formulář se vykreslí jen u volny.
foreach (['pouzity', 'volny'] as $name) {
	FileSystem::write($project . "/blocks/{$name}.json", json_encode([
		'name' => $name, 'command' => 'echo', 'args' => [],
	]));
}

FileSystem::write($project . '/workflows/w.json', json_encode([
	'name' => 'w',
	'steps' => [['type' => 'run', 'block' => 'pouzity']],
]));

// --- podvržená cesta se srovná na jméno a narazí na kontrolu použití ---

[$response, $html] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'volny', 'do' => 'deleteForm-submit'],
	['name' => '../blocks/pouzity', 'delete' => 'Smazat'],
);

Assert::false($response instanceof RedirectResponse);
Assert::true(is_file($project . '/blocks/pouzity.json'), 'soubor musí zůstat');

// Hláška musí být od kontroly použití, ne „kámen neexistuje" — o osudu
// souboru nesmí rozhodovat až klíčování mapy v BlockRepository.
Assert::contains('nejde smazat', $html, 'kontrola použití se musí ptát na srovnané jméno, ne na cestu');

// --- cesta ven z blocks/ nesmaže nic ---

FileSystem::write($project . '/tajne.json', '{}');

[$response] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'volny', 'do' => 'deleteForm-submit'],
	['name' => '../tajne', 'delete' => 'Smazat'],
);

Assert::false($response instanceof RedirectResponse);
Assert::true(is_file($project . '/tajne.json'), 'mimo blocks/ se mazat nesmí');

FileSystem::delete(TEMP_DIR);
