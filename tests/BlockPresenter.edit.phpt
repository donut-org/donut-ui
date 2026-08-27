<?php

declare(strict_types=1);

use Donut\Parser\BlockParser;
use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Nette\Application\BadRequestException;
use Nette\Application\Request as NetteRequest;
use Donut\Profile;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/blockPresenter.php';

$project = TEMP_DIR . '/edit';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');

FileSystem::write($project . '/blocks/echo.json', json_encode([
	'name' => 'echo',
	'description' => 'Prints text',
	'command' => 'echo',
	'args' => [['{%text%}']],
	'inputs' => ['text' => ['required' => true]],
	'timeout' => 5,
]));

// --- editing an existing one: the form is prefilled ---

[, $html] = runBlockPresenterIn($project, ['action' => 'edit', 'name' => 'echo']);

Assert::contains('value="echo"', $html);
Assert::contains('Prints text', $html);
Assert::contains('{%text%}', $html);
Assert::contains('value="5"', $html);

// --- stdin is one field with three options, not two checkboxes ---
//
// The pair it replaced could say "does not read stdin" and "stdin is
// required" at once, which no block file can mean — the required flag lives
// inside the stdin object. Worse, that was the state every block without
// stdin opened in, because the flag fell back to StdinSpec's default of
// true with no spec to read it from. And it was thrown away on save.

Assert::notContains('name="hasStdin"', $html);
Assert::notContains('name="stdinRequired"', $html);
Assert::match('~<select[^>]*name="stdin"~', $html);
Assert::contains('does not read stdin', $html);
Assert::contains('reads stdin, optional', $html);
Assert::contains('requires stdin', $html);

// echo does not read stdin, so that is the option standing — and nothing
// else claims otherwise.
Assert::match('~<option value="no" selected~', $html);

// The description hides when the block reads nothing. netteForms.js does
// the hiding and addresses the row by this id; the toggle rule travels in
// the select's own attribute.
Assert::contains('id=stdin-description', $html);
Assert::match('~name="stdin"[^>]*data-nette-rules=[^>]*stdin-description~', $html);

$stdinBlock = TEMP_DIR . '/edit-stdin';
FileSystem::createDir($stdinBlock . '/blocks');
FileSystem::createDir($stdinBlock . '/workflows');
FileSystem::write($stdinBlock . '/blocks/cat.json', json_encode([
	'name' => 'cat', 'command' => 'cat', 'args' => [],
	'stdin' => ['required' => false, 'description' => 'Anything'],
]));

[, $catHtml] = runBlockPresenterIn($stdinBlock, ['action' => 'edit', 'name' => 'cat']);
Assert::match('~<option value="optional" selected~', $catHtml, 'required false is the middle option');
Assert::contains('value="Anything"', $catHtml);

// --- creating a new one: an empty form, no crash ---

[, $new] = runBlockPresenterIn($project, ['action' => 'edit']);

Assert::contains('<form', $new);
Assert::notContains('Prints text', $new);

// --- a nonexistent block is a 404, not a page about one ---
//
// It used to render the edit form with the message in it, which made a typo
// in the address bar indistinguishable from a block that exists.

$e = Assert::exception(
	fn() => createBlockPresenter([], true, new Profile(\basename($project), $project))
		->run(new NetteRequest('Block', 'GET', ['action' => 'edit', 'name' => 'missing'])),
	BadRequestException::class,
);
Assert::same(404, $e->getHttpCode());
Assert::contains("Block 'missing' does not exist.", $e->getMessage());

// --- saving: a valid block goes through and a file is created ---

$post = [
	'name' => 'added',
	'description' => 'Description',
	'command' => 'curl',
	'args' => [
		// The gap in numbering is deliberate — JS doesn't renumber rows.
		0 => [0 => '-sS'],
		2 => [0 => '{%url%}'],
	],
	'inputs' => [
		1 => ['name' => 'url', 'required' => '1', 'default' => '', 'description' => 'Address'],
	],
	'timeout' => '',
	'stdin' => 'no',
	'allowFailure' => 'none',
	'allowFailureCodes' => '',
	'save' => 'Save',
];

[$response] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'do' => 'blockForm-submit'],
	$post,
);

// Success ends with a redirect to editing the saved block.
Assert::type(RedirectResponse::class, $response);

$saved = (new BlockParser)->parseFile($project . '/blocks/added.json');
Assert::same('added', $saved->name);
Assert::same('curl', $saved->command);

// The gap in indices closed up and the order was kept.
Assert::same('-sS', $saved->args[0][0]->getSource());
Assert::same('{%url%}', $saved->args[1][0]->getSource());
Assert::same(['url'], array_keys($saved->inputs));

// --- saving: an undeclared variable in args is rejected ---

$invalid = ['name' => 'bad', 'args' => [0 => [0 => '{%undeclared%}']], 'inputs' => []] + $post;

[$response, $html] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'do' => 'blockForm-submit'],
	$invalid,
);

// No redirect — the form came back with an error.
Assert::false($response instanceof RedirectResponse);
Assert::contains('undeclared', $html);
Assert::false(is_file($project . '/blocks/bad.json'));

// The message belongs among the errors, not the warnings — otherwise the
// user would see the specific rejection reason as a mere warning and only
// the generic message as an error. Both would pass the Assert::contains()
// above; this is a safeguard against the severities being swapped in the
// template.
//
// .*? between <div> and </div> would skip across the rest of the page to
// the first following </div> after the word "undeclared" — and that's also the
// form's div with the generic "The block was not saved" message; "undeclared"
// shows up again further down in the args field's value. The pattern is
// therefore tied to the structure from Step 2 (div > ul.mb-0 > li) without
// jumping across another tag.
Assert::match('~<div class="alert alert-danger">\s*<ul class="mb-0">\s*<li>[^<]*undeclared[^<]*</li>~s', $html);
Assert::notMatch('~<div class="alert alert-warning">\s*<ul class="mb-0">\s*<li>[^<]*undeclared[^<]*</li>~s', $html);

// --- creating must not overwrite an existing block ---
// writeFile() overwrites without asking; without a safeguard this POST
// would silently discard echo.json's content and return a redirect
// indistinguishable from success.

$echoBefore = FileSystem::read($project . '/blocks/echo.json');

[$response, $html] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'do' => 'blockForm-submit'],
	['name' => 'echo'] + $post,
);

// No redirect, and the file unchanged byte for byte.
Assert::false($response instanceof RedirectResponse);
Assert::contains('Block "echo" already exists. Edit it, or choose another name.', $html);
Assert::same($echoBefore, FileSystem::read($project . '/blocks/echo.json'));

// --- the posted name is ignored while editing — "renaming" must not fork the file ---
// The name is non-editable (setDisabled()), so even a manually posted
// different name ends up saved under the block's original name, not as a
// new file.

[$response] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'echo', 'do' => 'blockForm-submit'],
	[
		'name' => 'renamed',
		'description' => 'Prints text',
		'command' => 'echo',
		'args' => [0 => [0 => '{%text%}']],
		'inputs' => [0 => ['name' => 'text', 'required' => '1', 'default' => '', 'description' => '']],
		'timeout' => '5',
		'stdin' => 'no',
		'allowFailure' => 'none',
		'allowFailureCodes' => '',
		'save' => 'Save',
	],
);

Assert::type(RedirectResponse::class, $response);
Assert::true(is_file($project . '/blocks/echo.json'));
Assert::false(is_file($project . '/blocks/renamed.json'));

// --- creating without a blocks directory is reported, not a crash ---
//
// Saving into a profile whose blocks/ doesn't exist yet must work — on a
// fresh project that's the normal state, and the GUI is where the user has
// no shell at hand. BlockStore::save() creates the directory, so this ends
// in a redirect like any other save, not in a form error.

$noBlocksDir = TEMP_DIR . '/edit-no-blocks-dir';
FileSystem::createDir($noBlocksDir);

[$response, $html] = runBlockPresenterIn(
	$noBlocksDir,
	['action' => 'edit', 'do' => 'blockForm-submit'],
	[
		'name' => 'added',
		'description' => '',
		'command' => 'echo',
		'args' => [],
		'inputs' => [],
		'timeout' => '',
		'stdin' => 'no',
		'allowFailure' => 'none',
		'allowFailureCodes' => '',
		'save' => 'Save',
	],
);

Assert::true($response instanceof RedirectResponse, 'a save into a fresh profile must succeed');
Assert::true(\is_dir($noBlocksDir . '/blocks'), 'the save creates the blocks directory');
Assert::true(\is_file($noBlocksDir . '/blocks/added.json'), 'and writes the block into it');

// --- and when the directory can't be created, that's a form error too ---
//
// FileSystem::createDir() throws an IOException, not a WriteException, so
// blockFormSucceeded() has to catch it as well — otherwise a profile with a
// file where blocks/ should be answers with a 500 instead of a message.

$blockedDir = TEMP_DIR . '/edit-blocks-is-a-file';
FileSystem::createDir($blockedDir);
FileSystem::write($blockedDir . '/blocks', 'not a directory');

[$response, $html] = runBlockPresenterIn(
	$blockedDir,
	['action' => 'edit', 'do' => 'blockForm-submit'],
	[
		'name' => 'added',
		'description' => '',
		'command' => 'echo',
		'args' => [],
		'inputs' => [],
		'timeout' => '',
		'stdin' => 'no',
		'allowFailure' => 'none',
		'allowFailureCodes' => '',
		'save' => 'Save',
	],
);

Assert::false($response instanceof RedirectResponse, 'a directory that can\'t be created must not end in a redirect');
Assert::contains('Unable to create directory', $html);

// --- a foreign POST must not empty the block form ---
//
// I2: formShape() asks whether the POST belongs to blockForm, but
// setDefaults() used to ask only "is this a POST?". After a failed
// deletion the form had the right shape and all values empty — and
// "Save" would have written it exactly like that.

[$response, $html] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'echo', 'do' => 'deleteForm-submit'],
	['name' => 'missing', 'delete' => 'Delete'],
);

Assert::false($response instanceof RedirectResponse, 'deletion failed, the page re-rendered');
Assert::contains('value="echo"', $html, 'the name is held by setDefaultValue()');
Assert::contains('Prints text', $html, 'the description must not be lost');
Assert::contains('{%text%}', $html, 'the arguments must not be lost');
Assert::contains('value="5"', $html, 'the timeout must not be lost');

// --- N1: a GET with `do=blockForm-submit` in the address is still a GET ---
// A manually crafted address carries the signal but no data — getPost()
// returns [], which isn't an empty form POST. Without a check on the HTTP
// method, setDefaults() gets skipped, the form renders empty, and "Save"
// would write the block like that.

[, $html] = runBlockPresenterIn($project, ['action' => 'edit', 'name' => 'echo', 'do' => 'blockForm-submit']);

Assert::contains('value="echo"', $html, 'the name is held by setDefaultValue()');
Assert::contains('Prints text', $html, 'the description must not be lost — GET sent nothing');
// The name and the command happen to both be "echo" here — so the command
// must be asked about specifically, or the assertion would pass on the
// prefilled name instead.
Assert::match('~name="command"[^>]*value="echo"~', $html, 'the command must not be lost');
Assert::contains('{%text%}', $html, 'the arguments must not be lost');

// --- a POST from a foreign site must not reach the write ---
// Same check as on the other half of the GUI (WorkflowPresenter.headerForm.phpt):
// the GUI has no CSRF token or session, only Fetch Metadata in
// Form::signalReceived() covers it. Without the sec-fetch-site header the
// signal ends up in detectedCsrf() → redirect('this'), so the disk decides
// the outcome, not the response type — a redirect would arrive even after
// a successful save.

$before = FileSystem::read($project . '/blocks/echo.json');

[$response] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'echo', 'do' => 'blockForm-submit'],
	[
		'name' => 'echo',
		'description' => 'From a foreign site',
		'command' => 'echo',
		'args' => [],
		'inputs' => [],
		'timeout' => '',
		'stdin' => 'no',
		'allowFailure' => 'none',
		'allowFailureCodes' => '',
		'save' => 'Save',
	],
	sameOrigin: false,
);

Assert::type(RedirectResponse::class, $response);
Assert::same($before, FileSystem::read($project . '/blocks/echo.json'), 'a foreign origin must not write anything');

FileSystem::delete(TEMP_DIR);
