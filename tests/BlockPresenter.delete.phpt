<?php

declare(strict_types=1);

use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/blockPresenter.php';

$project = TEMP_DIR . '/delete';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');

// pouzity je v workflow, volny ne.
foreach (['pouzity', 'volny'] as $name) {
	FileSystem::write($project . "/blocks/{$name}.json", json_encode([
		'name' => $name, 'command' => 'echo', 'args' => [],
	]));
}

FileSystem::write($project . '/workflows/w.json', json_encode([
	'name' => 'w',
	'steps' => [['type' => 'run', 'block' => 'pouzity']],
]));

// --- přehled ukazuje, kdo který kámen používá ---

[, $html] = runBlockPresenterIn($project, ['action' => 'default']);

Assert::contains('používá', $html);
Assert::contains('w', $html);

// --- editace volného kamene nabídne mazání ---

[, $html] = runBlockPresenterIn($project, ['action' => 'edit', 'name' => 'volny']);
Assert::contains('Smazat', $html);

// --- editace použitého kamene mazání nenabídne a řekne proč ---

[, $html] = runBlockPresenterIn($project, ['action' => 'edit', 'name' => 'pouzity']);
Assert::notContains('Smazat', $html);
Assert::contains('používá', $html);
Assert::contains('w', $html);

// --- POST na použitý kámen se odmítne, i když tlačítko v HTML nebylo ---
// Šablona tlačítko schová, prezenter to ohlídá. Obojí schválně.

[$response, $html] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'pouzity', 'do' => 'deleteForm-submit'],
	['name' => 'pouzity', 'delete' => 'Smazat'],
);

Assert::false($response instanceof RedirectResponse);
Assert::true(is_file($project . '/blocks/pouzity.json'));

// --- volný kámen se smaže a přesměruje se do přehledu ---

[$response] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'volny', 'do' => 'deleteForm-submit'],
	['name' => 'volny', 'delete' => 'Smazat'],
);

Assert::type(RedirectResponse::class, $response);
Assert::false(is_file($project . '/blocks/volny.json'));

FileSystem::delete(TEMP_DIR);
