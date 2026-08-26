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

// block "used" is referenced by a workflow, "free" isn't.
foreach (['used', 'free'] as $name) {
	FileSystem::write($project . "/blocks/{$name}.json", json_encode([
		'name' => $name, 'command' => 'echo', 'args' => [],
	]));
}

FileSystem::write($project . '/workflows/w.json', json_encode([
	'name' => 'w',
	'steps' => [['type' => 'run', 'block' => 'used']],
]));

// --- overview shows who uses which block ---

[, $html] = runBlockPresenterIn($project, ['action' => 'default']);

// The table shows which workflow calls the block. This used to check the
// Czech word for "used by" from the sentence under the heading — in the
// table it became the column header. So we ask about the cell content
// instead; `contains('w')` alone asserted nothing, because the letter w is
// everywhere in the HTML (workflow, www).
Assert::match('~<td>\s*w\s*</td>~', $html);

// --- editing a free block offers deletion ---

[, $html] = runBlockPresenterIn($project, ['action' => 'edit', 'name' => 'free']);
Assert::contains('Delete', $html);

// --- editing a used block doesn't offer deletion, and says why ---

[, $html] = runBlockPresenterIn($project, ['action' => 'edit', 'name' => 'used']);
// this used to target '<h2>Smazat</h2>' — that heading is gone (Task 3,
// cards). For a block (unlike a workflow), the "Delete" card renders
// whenever it has a name — for a used block its body just has the sentence
// "cannot be deleted…", not the delete form. notContains('card
// border-danger', ...) would always fail here, because the card shows up
// for a used block too — so we target the delete form directly, which must
// not render.
Assert::notContains('id="frm-deleteForm"', $html);
Assert::contains('used by', $html);
Assert::contains('w', $html);

// --- a POST on a used block is rejected, even though the button wasn't in the HTML ---
// The template hides the button, the presenter guards it too. Both deliberately.

[$response, $html] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'used', 'do' => 'deleteForm-submit'],
	['name' => 'used', 'delete' => 'Delete'],
);

Assert::false($response instanceof RedirectResponse);
Assert::true(is_file($project . '/blocks/used.json'));

// The page is still editing "used" and its content must not disappear
// just because the POST belonged to deleteForm, not blockForm — formShape()
// used to react to any POST and built the form with zero argument groups.
Assert::contains('value="used"', $html);
// (since fix I4, an argument group has Bootstrap classes alongside
// arg-group, so we look for the start of the class list, not the whole
// attribute)
Assert::match('~<div class="arg-group\b~', $html);

// --- a free block gets deleted and redirects to the overview ---

[$response] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'free', 'do' => 'deleteForm-submit'],
	['name' => 'free', 'delete' => 'Delete'],
);

Assert::type(RedirectResponse::class, $response);
Assert::false(is_file($project . '/blocks/free.json'));

// --- a block that fails to parse can still be deleted ---
// The delete section used to sit inside {if !$error}, so a broken file — the
// one you most want to remove — offered no way out.

FileSystem::write($project . '/blocks/broken.json', '{not valid json');

[, $html] = runBlockPresenterIn($project, ['action' => 'edit', 'name' => 'broken']);

// $error is set (the file failed to parse)...
Assert::contains('alert-danger', $html);
// ...but the Delete button still shows.
Assert::contains('Delete', $html);

FileSystem::delete(TEMP_DIR);
