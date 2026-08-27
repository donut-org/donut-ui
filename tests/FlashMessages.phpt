<?php

declare(strict_types=1);

use Donut\Profile;
use Nette\Application\Request as NetteRequest;
use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';
require __DIR__ . '/inc/blockPresenter.php';

// Every successful save and delete ends in a redirect, and a redirect is
// silent: the user is moved to another page with nothing to say the write
// happened. The flash message is the only confirmation, so it names the
// thing it is confirming — after a delete especially, where the page the
// user lands on no longer mentions it anywhere.

$dir = TEMP_DIR . '/profile';
FileSystem::createDir($dir . '/workflows');
FileSystem::createDir($dir . '/blocks');

FileSystem::write($dir . '/blocks/echo.json', json_encode([
	'name' => 'echo', 'command' => 'echo', 'args' => [],
]));
FileSystem::write($dir . '/workflows/w.json', json_encode([
	'name' => 'w', 'steps' => [],
]));


/**
 * @param  array<string, mixed> $params
 * @param  array<string, mixed> $post
 * @return array<int, string> the flash messages the presenter left behind
 */
function flashesAfterBlock(string $dir, array $params, array $post): array
{
	$presenter = createBlockPresenter($post, true, new Profile(\basename($dir), $dir));
	$response = $presenter->run(new NetteRequest('Block', 'POST', $params, $post));

	Assert::type(RedirectResponse::class, $response, 'the write must have succeeded');

	return messagesOf($presenter);
}


/**
 * @param  array<string, mixed> $params
 * @param  array<string, mixed> $post
 * @return array<int, string>
 */
function flashesAfterWorkflow(string $dir, array $params, array $post): array
{
	$presenter = createWorkflowPresenter($post, true, new Profile(\basename($dir), $dir));
	$response = $presenter->run(new NetteRequest('Workflow', 'POST', $params, $post));

	Assert::type(RedirectResponse::class, $response, 'the write must have succeeded');

	return messagesOf($presenter);
}


/** @return array<int, string> */
function messagesOf(Nette\Application\UI\Presenter $presenter): array
{
	$flashes = $presenter->getTemplate()->flashes ?? [];

	return \array_map(fn(\stdClass $flash) => (string) $flash->message, \is_array($flashes) ? $flashes : []);
}


// --- saving a block ---

Assert::same(
	['Block "added" saved.'],
	flashesAfterBlock($dir, ['action' => 'edit', 'do' => 'blockForm-submit'], [
		'name' => 'added',
		'description' => '',
		'command' => 'echo',
		'args' => [],
		'inputs' => [],
		'timeout' => '',
		'allowFailure' => 'none',
		'allowFailureCodes' => '',
		'save' => 'Save',
	]),
);

// --- deleting a block ---

Assert::same(
	['Block "echo" deleted.'],
	flashesAfterBlock($dir, ['action' => 'edit', 'name' => 'echo', 'do' => 'deleteForm-submit'], [
		'name' => 'echo',
		'delete' => 'Delete',
	]),
);

// --- saving a workflow header ---

Assert::same(
	['Workflow "w" saved.'],
	flashesAfterWorkflow($dir, ['action' => 'edit', 'name' => 'w', 'do' => 'headerForm-submit'], [
		'name' => 'w',
		'description' => 'Changed',
		'inputs' => [],
		'save' => 'Save',
	]),
);

// --- deleting a workflow ---

Assert::same(
	['Workflow "w" deleted.'],
	flashesAfterWorkflow($dir, ['action' => 'edit', 'name' => 'w', 'do' => 'deleteWorkflowForm-submit'], [
		'name' => 'w',
		'delete' => 'Delete',
	]),
);

// --- and the next page actually shows it ---
//
// The whole point is the round trip: the presenter that writes the message
// redirects away, so the message has to survive into the request that
// follows, carried by the _fid in the redirect URL. Asserting only that the
// flash was set would leave the half the user sees untested.
//
// This runs in one process, so MemorySession's $_SESSION carries over
// exactly as a real session cookie would.

$presenter = createBlockPresenter([], true, new Profile(\basename($dir), $dir));
$response = $presenter->run(new NetteRequest('Block', 'POST', [
	'action' => 'edit', 'do' => 'blockForm-submit',
], [
	'name' => 'roundtrip',
	'description' => '',
	'command' => 'echo',
	'args' => [],
	'inputs' => [],
	'timeout' => '',
	'allowFailure' => 'none',
	'allowFailureCodes' => '',
	'save' => 'Save',
]));

Assert::type(RedirectResponse::class, $response);
Assert::match('~_fid=\w+~', $response->getUrl(), 'the redirect carries the flash id');

\preg_match('~_fid=(\w+)~', $response->getUrl(), $m);

[, $html] = runBlockPresenterIn($dir, ['action' => 'edit', 'name' => 'roundtrip', '_fid' => $m[1]]);

// Latte leaves a double quote alone in text content, where it needs no
// escaping — the assertion follows what is rendered, not what looks safe.
Assert::contains('<div class="alert alert-success" role=status>Block "roundtrip" saved.</div>', $html);

// Escaping of the message itself isn't asserted here: it is Latte escaping
// {$flash->message} in @layout.latte, not this project's code, and the block
// name — the only user input that reaches a flash — is validated before it
// ever gets there, so the real path cannot carry markup into one anyway.

FileSystem::delete(TEMP_DIR);
