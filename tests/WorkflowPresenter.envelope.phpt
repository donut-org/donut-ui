<?php

declare(strict_types=1);

use Donut\Parser\WorkflowParser;
use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

$project = TEMP_DIR . '/envelope';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');

FileSystem::write($project . '/workflows/w.json', json_encode([
	'name' => 'w',
	'description' => 'Runs after every push.',
	'inputs' => ['repo' => ['required' => true, 'description' => 'Repository']],
	'steps' => [['type' => 'set', 'key' => 'a', 'value' => '1']],
]));

$load = fn(string $name) => (new WorkflowParser)->parseFile($project . "/workflows/{$name}.json");

// --- editing: the form is prefilled ---

[, $html] = runWorkflowPresenterIn($project, ['action' => 'edit', 'name' => 'w']);

Assert::contains('value="w"', $html);
Assert::contains('Runs after every push.', $html);
Assert::contains('repo', $html);

// the delete section is a card with a red border — the boundary of an
// irreversible operation should be visible before the user clicks into it
Assert::match('~<div class="card border-danger[^"]*">~', $html);

// --- creating: an empty form, no crash ---

[, $newHtml] = runWorkflowPresenterIn($project, ['action' => 'edit']);

Assert::contains('<form', $newHtml);
Assert::notContains('Repository', $newHtml);

// --- creating saves an empty workflow ---

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'do' => 'headerForm-submit'],
	[
		'name' => 'new',
		'description' => 'New',
		'inputs' => [0 => ['name' => 'x', 'required' => '1', 'default' => '', 'description' => '']],
		'save' => 'Save',
	],
);

Assert::type(RedirectResponse::class, $response);

$new = $load('new');
Assert::same('new', $new->name);
Assert::same('New', $new->description);
Assert::same(['x'], array_keys($new->inputs));
Assert::same([], $new->steps, 'a new workflow is created empty');

// --- creating under an existing name does NOT overwrite ---
//
// A lesson from editing a block, where it was Critical: writeFile() overwrites
// without asking, and the redirect looks like success.

$before = FileSystem::read($project . '/workflows/w.json');

[$response, $html] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'do' => 'headerForm-submit'],
	['name' => 'w', 'description' => 'Overwrite', 'inputs' => [], 'save' => 'Save'],
);

Assert::false($response instanceof RedirectResponse, 'an overwrite must not look like success');
Assert::contains('Workflow "w" already exists. Edit it, or choose another name.', $html);
Assert::same($before, FileSystem::read($project . '/workflows/w.json'), 'the original file must stay byte for byte the same');

// --- editing must not lose steps ---

runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'w', 'do' => 'headerForm-submit'],
	['name' => 'w', 'description' => 'Different description', 'inputs' => [], 'save' => 'Save'],
);

$edited = $load('w');
Assert::same('Different description', $edited->description);
Assert::count(1, $edited->steps, 'editing the header must not lose the steps');

// --- submitting a changed name on edit writes the original ---
//
// A lesson from editing a block, where it was Important: a "rename" otherwise
// forks the object in two.

runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'w', 'do' => 'headerForm-submit'],
	['name' => 'renamed', 'description' => 'X', 'inputs' => [], 'save' => 'Save'],
);

Assert::true(\is_file($project . '/workflows/w.json'));
Assert::false(\is_file($project . '/workflows/renamed.json'), 'a second file must not appear');

// --- deleting ---

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'new', 'do' => 'deleteWorkflowForm-submit'],
	['name' => 'new', 'save' => 'Delete'],
);

Assert::type(RedirectResponse::class, $response);
Assert::false(\is_file($project . '/workflows/new.json'));

// --- deleting isn't offered while creating ---

[, $newHtml] = runWorkflowPresenterIn($project, ['action' => 'edit']);
// this used to target '<h2>Smazat</h2>' — that heading is gone (Task 3,
// cards), replaced by the card header. The delete section doesn't render at
// all while creating (the template wraps it in {if $name !== null}), so the
// card with the border-danger class doesn't appear — that's what we target
// now.
Assert::notContains('card border-danger', $newHtml);

// --- the list offers creating ---

[, $list] = runWorkflowPresenterIn($project, ['action' => 'default']);
Assert::contains('new workflow', $list);

// --- creating without a workflows directory is reported, doesn't crash ---
//
// WorkflowStore::__construct() throws a ParseException when the workflows
// directory doesn't exist — on a fresh project that's the normal state.
// headerFormSucceeded() must catch it just as gently as WriteException, not
// let the exception fall through uncaught.

$withoutDirectory = TEMP_DIR . '/envelope-without-workflows';
FileSystem::createDir($withoutDirectory);

[$response, $html] = runWorkflowPresenterIn(
	$withoutDirectory,
	['action' => 'edit', 'do' => 'headerForm-submit'],
	['name' => 'new', 'description' => '', 'inputs' => [], 'save' => 'Save'],
);

Assert::false($response instanceof RedirectResponse, 'a missing directory must not end in a redirect');
Assert::contains('does not exist', $html);
// M8: the message must say what to do about it — otherwise a fresh project
// is a dead end.
Assert::contains('mkdir -p ' . $withoutDirectory . '/workflows', $html);

FileSystem::delete(TEMP_DIR);
