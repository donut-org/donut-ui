<?php

declare(strict_types=1);

use Donut\Profile;
use Nette\Application\BadRequestException;
use Nette\Application\Request;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';
require __DIR__ . '/inc/blockPresenter.php';

// A URL that names a workflow or a block which isn't there must answer 404.
// It used to render an ordinary page with the error in it, so a typo in the
// address bar looked to every client — a browser, curl, a link checker —
// exactly like a page that exists.
//
// The distinction that makes this safe is NotFoundException: a file that
// exists and won't parse keeps rendering its error at 200, because that
// resource is there and hiding it behind a 404 would lose the one message
// that says what to fix.

$dir = TEMP_DIR . '/profile';
FileSystem::createDir($dir . '/workflows');
FileSystem::createDir($dir . '/blocks');

FileSystem::write($dir . '/workflows/sync.json', json_encode(['name' => 'sync', 'steps' => []]));
FileSystem::write($dir . '/workflows/broken.json', '{not valid json');


/** @param array<string, mixed> $params */
function runWorkflow(string $dir, array $params): mixed
{
	$presenter = createWorkflowPresenter([], true, new Profile(\basename($dir), $dir));

	return $presenter->run(new Request('Workflow', 'GET', $params));
}


/** @param array<string, mixed> $params */
function runBlock(string $dir, array $params): mixed
{
	$presenter = createBlockPresenter([], true, new Profile(\basename($dir), $dir));

	return $presenter->run(new Request('Block', 'GET', $params));
}


// --- a workflow that isn't there ---

$e = Assert::exception(
	fn() => runWorkflow($dir, ['action' => 'detail', 'name' => 'nope']),
	BadRequestException::class,
);
Assert::same(404, $e->getHttpCode());
// The message still names the directory that was searched — a 404 with
// nothing in it would be a worse answer than the 200 it replaces.
Assert::contains('Workflow "nope" does not exist.', $e->getMessage());

Assert::exception(
	fn() => runWorkflow($dir, ['action' => 'edit', 'name' => 'nope']),
	BadRequestException::class,
);

// --- a block that isn't there ---

$e = Assert::exception(
	fn() => runBlock($dir, ['action' => 'detail', 'name' => 'nope']),
	BadRequestException::class,
);
Assert::same(404, $e->getHttpCode());

Assert::exception(
	fn() => runBlock($dir, ['action' => 'edit', 'name' => 'nope']),
	BadRequestException::class,
);

// --- a broken file is not a missing one ---
//
// This is the assertion the whole distinction exists for: turning every
// ParseException into a 404 would swallow the parse error, and the file the
// user most needs to fix is the one they could no longer see.

[, $html] = runWorkflowPresenterIn($dir, ['action' => 'detail', 'name' => 'broken']);
Assert::contains('broken', $html);

// --- and a workflow that is there still renders ---

[, $html] = runWorkflowPresenterIn($dir, ['action' => 'detail', 'name' => 'sync']);
Assert::contains('sync', $html);

FileSystem::delete(TEMP_DIR);
