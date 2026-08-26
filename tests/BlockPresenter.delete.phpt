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

// pouzity is used by a workflow, volny isn't.
foreach (['pouzity', 'volny'] as $name) {
	FileSystem::write($project . "/blocks/{$name}.json", json_encode([
		'name' => $name, 'command' => 'echo', 'args' => [],
	]));
}

FileSystem::write($project . '/workflows/w.json', json_encode([
	'name' => 'w',
	'steps' => [['type' => 'run', 'block' => 'pouzity']],
]));

// --- overview shows who uses which block ---

[, $html] = runBlockPresenterIn($project, ['action' => 'default']);

// The table shows which workflow calls the block. This used to check the
// word "používá" (used by) from the sentence under the heading — in the
// table it became the column header. So we ask about the cell content
// instead; `contains('w')` alone asserted nothing, because the letter w is
// everywhere in the HTML (workflow, www).
Assert::match('~<td>\s*w\s*</td>~', $html);

// --- editing a free block offers deletion ---

[, $html] = runBlockPresenterIn($project, ['action' => 'edit', 'name' => 'volny']);
Assert::contains('Smazat', $html);

// --- editing a used block doesn't offer deletion, and says why ---

[, $html] = runBlockPresenterIn($project, ['action' => 'edit', 'name' => 'pouzity']);
// this used to target '<h2>Smazat</h2>' — that heading is gone (Task 3,
// cards). For a block (unlike a workflow), the "Smazat" card renders
// whenever it has a name — for a used block its body just has the sentence
// "cannot be deleted…", not the delete form. notContains('card
// border-danger', ...) would always fail here, because the card shows up
// for a used block too — so we target the delete form directly, which must
// not render.
Assert::notContains('id="frm-deleteForm"', $html);
Assert::contains('používá', $html);
Assert::contains('w', $html);

// --- a POST on a used block is rejected, even though the button wasn't in the HTML ---
// The template hides the button, the presenter guards it too. Both deliberately.

[$response, $html] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'pouzity', 'do' => 'deleteForm-submit'],
	['name' => 'pouzity', 'delete' => 'Delete'],
);

Assert::false($response instanceof RedirectResponse);
Assert::true(is_file($project . '/blocks/pouzity.json'));

// The page is still editing "pouzity" and its content must not disappear
// just because the POST belonged to deleteForm, not blockForm — formShape()
// used to react to any POST and built the form with zero argument groups.
Assert::contains('value="pouzity"', $html);
// (since fix I4, an argument group has Bootstrap classes alongside
// arg-group, so we look for the start of the class list, not the whole
// attribute)
Assert::match('~<div class="arg-group\b~', $html);

// --- a free block gets deleted and redirects to the overview ---

[$response] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'volny', 'do' => 'deleteForm-submit'],
	['name' => 'volny', 'delete' => 'Delete'],
);

Assert::type(RedirectResponse::class, $response);
Assert::false(is_file($project . '/blocks/volny.json'));

// --- a block that fails to parse can still be deleted ---
// The delete section used to sit inside {if !$error}, so a broken file — the
// one you most want to remove — offered no way out.

FileSystem::write($project . '/blocks/broken.json', '{not valid json');

[, $html] = runBlockPresenterIn($project, ['action' => 'edit', 'name' => 'broken']);

// $error is set (the file failed to parse)...
Assert::contains('alert-danger', $html);
// ...but the Smazat (delete) button still shows.
Assert::contains('Smazat', $html);

FileSystem::delete(TEMP_DIR);
