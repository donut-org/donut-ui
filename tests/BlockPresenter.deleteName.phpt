<?php

declare(strict_types=1);

use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/blockPresenter.php';

// N7: the name from the hidden field goes through basename(), same as for
// a workflow. The file itself is safe even without it (BlockStore::exists()
// looks it up in a map keyed by basename($path, '.json'), so a name with a
// slash can never be a key in it), but the usage check compares the name
// from the request directly — and without basename() it won't match a
// forged path, and the deletion decision ends up made by the distant
// storage implementation.

$project = TEMP_DIR . '/delete-name';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');

// pouzity is used by a workflow, volny isn't — the delete form renders only for volny.
foreach (['pouzity', 'volny'] as $name) {
	FileSystem::write($project . "/blocks/{$name}.json", json_encode([
		'name' => $name, 'command' => 'echo', 'args' => [],
	]));
}

FileSystem::write($project . '/workflows/w.json', json_encode([
	'name' => 'w',
	'steps' => [['type' => 'run', 'block' => 'pouzity']],
]));

// --- a forged path normalizes to the name and hits the usage check ---

[$response, $html] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'volny', 'do' => 'deleteForm-submit'],
	['name' => '../blocks/pouzity', 'delete' => 'Delete'],
);

Assert::false($response instanceof RedirectResponse);
Assert::true(is_file($project . '/blocks/pouzity.json'), 'the file must remain');

// The message must come from the usage check, not "block does not exist" —
// the fate of the file must not be decided all the way down at the map
// keying in BlockRepository.
Assert::contains('cannot be deleted', $html, 'usage check must ask about the normalized name, not the path');

// --- a path out of blocks/ deletes nothing ---

FileSystem::write($project . '/tajne.json', '{}');

[$response] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'volny', 'do' => 'deleteForm-submit'],
	['name' => '../tajne', 'delete' => 'Delete'],
);

Assert::false($response instanceof RedirectResponse);
Assert::true(is_file($project . '/tajne.json'), 'nothing outside blocks/ may be deleted');

// --- an empty name is reported the same way as for a workflow ---
// Without the guard, an empty string would fall all the way through to the
// storage, and the page would answer 'Block "" does not exist. Searched in:
// …/blocks', which tells the user nothing. deleteWorkflowFormSucceeded() has
// this guard; both halves of the GUI should answer the same way.

[$response, $html] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'volny', 'do' => 'deleteForm-submit'],
	['name' => '', 'delete' => 'Delete'],
);

Assert::false($response instanceof RedirectResponse);
Assert::contains('Nothing to delete.', $html);
Assert::true(is_file($project . '/blocks/volny.json'), 'nothing should have been deleted');

FileSystem::delete(TEMP_DIR);
