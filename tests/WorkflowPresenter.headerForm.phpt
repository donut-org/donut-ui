<?php

declare(strict_types=1);

use Donut\Parser\WorkflowParser;
use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

// I2: the header form is built even on a POST that doesn't belong to it —
// it used to redraw empty in that case and "Save" wrote description: null
// and inputs: []. The question isn't "is this a POST?", but "does this POST
// belong to this form?".

$project = TEMP_DIR . '/header';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');

FileSystem::write($project . '/workflows/w.json', json_encode([
	'name' => 'w',
	'description' => 'Important description',
	'inputs' => ['repo' => ['required' => true, 'description' => 'Repository']],
	'steps' => [['type' => 'set', 'key' => 'a', 'value' => '1']],
]));

// --- a foreign POST (a failed delete) must not empty the header form ---

[$response, $html] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'w', 'do' => 'deleteWorkflowForm-submit'],
	['name' => '', 'save' => 'Delete'],
);

Assert::false($response instanceof RedirectResponse, 'the delete failed, the page redrew');
Assert::contains('value="w"', $html, 'the name is held by setDefaultValue()');
Assert::contains('Important description', $html, 'the description must not be lost just because the POST belonged to a different form');
Assert::contains('value="repo"', $html, 'the inputs must not be lost');
Assert::contains('Repository', $html);


// I3: the shape of the `inputs` container is derived, on a POST, from the
// incoming data, not from the number of inputs on the loaded workflow. JS
// never renumbers rows (a contract from rows.latte), so indexes can have
// gaps — a container that doesn't get created for an incoming index means
// a silently lost input, and a redirect indistinguishable from success.

$load = fn(string $name) => (new WorkflowParser)->parseFile($project . "/workflows/{$name}.json");

// --- creating with inputs at indexes 0 and 3 saves both ---
// Exactly this kind of POST is what rows.latte produces after deleting the middle row.

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'do' => 'headerForm-submit'],
	[
		'name' => 'gaps',
		'description' => 'With two inputs',
		'inputs' => [
			0 => ['name' => 'a', 'required' => '1', 'default' => '', 'description' => ''],
			3 => ['name' => 'c', 'required' => '', 'default' => '', 'description' => ''],
		],
		'save' => 'Save',
	],
);

Assert::type(RedirectResponse::class, $response);
Assert::same(['a', 'c'], array_keys($load('gaps')->inputs), 'an input at an index with a gap must not be lost');

// --- editing: an input at a higher index than the workflow has ---

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'w', 'do' => 'headerForm-submit'],
	[
		'name' => 'w',
		'description' => 'Important description',
		'inputs' => [
			0 => ['name' => 'repo', 'required' => '1', 'default' => '', 'description' => 'Repository'],
			5 => ['name' => 'added', 'required' => '', 'default' => '', 'description' => ''],
		],
		'save' => 'Save',
	],
);

Assert::type(RedirectResponse::class, $response);
Assert::same(['repo', 'added'], array_keys($load('w')->inputs), 'an added input must not disappear just because it has a higher index');

// --- GET takes rows from the workflow, not from the (empty) POST ---
// Without the guard against a foreign signal, getPost() would return [] and
// the container would get a single row — the second input would never make
// it into the form.

[, $html] = runWorkflowPresenterIn($project, ['action' => 'edit', 'name' => 'w']);

Assert::contains('value="repo"', $html);
Assert::contains('value="added"', $html, 'both inputs must have their own row');

// --- N1: a GET with `do=headerForm-submit` in the address is still a GET ---
// A hand-built address (or a bookmark from before the redirect) carries the
// signal, but no data — getPost() returns [], which isn't null. Without the
// test on the HTTP method, setDefaults() would be skipped, the form would
// render empty and "Save" would write description: null and inputs: [].

[, $get] = runWorkflowPresenterIn($project, ['action' => 'edit', 'name' => 'w', 'do' => 'headerForm-submit']);

Assert::contains('value="w"', $get, 'the name is held by setDefaultValue()');
Assert::contains('Important description', $get, 'the description must not be lost — GET sent nothing');
Assert::contains('value="repo"', $get, 'the inputs must not be lost');
Assert::contains('value="added"', $get);

// --- a POST from a foreign site must not get through to a write ---
// The GUI has no CSRF token or session (readme, "What the GUI knowingly
// doesn't do") — the only protection is the Fetch Metadata check that Form
// does itself in signalReceived(). A request without the sec-fetch-site
// header is a foreign origin to Nette: it ends up in detectedCsrf() →
// redirect('this'), so the response is a redirect just like on success — so
// the disk decides, not the response type.

$before = FileSystem::read($project . '/workflows/w.json');

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'w', 'do' => 'headerForm-submit'],
	[
		'name' => 'w',
		'description' => 'From a foreign site',
		'inputs' => [],
		'save' => 'Save',
	],
	sameOrigin: false,
);

Assert::type(RedirectResponse::class, $response);
Assert::same($before, FileSystem::read($project . '/workflows/w.json'), 'a foreign origin must write nothing');

FileSystem::delete(TEMP_DIR);
