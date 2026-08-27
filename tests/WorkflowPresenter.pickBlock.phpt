<?php

declare(strict_types=1);

use Donut\Profile;
use Nette\Application\BadRequestException;
use Nette\Application\Request as NetteRequest;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

$project = TEMP_DIR . '/pick';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');

FileSystem::write($project . '/blocks/jq.json', json_encode([
	'name' => 'jq', 'command' => 'jq', 'args' => [],
	'description' => 'Filters JSON on stdin.',
	'inputs' => ['filter' => ['required' => true]],
]));
FileSystem::write($project . '/blocks/echo.json', json_encode([
	'name' => 'echo', 'command' => 'echo', 'args' => [],
]));
// Declares no `inputs`, but reads stdin — the step form will show one
// required slot for it, so the card has to count it too.
FileSystem::write($project . '/blocks/cat.json', json_encode([
	'name' => 'cat', 'command' => 'cat', 'args' => [],
	'stdin' => ['required' => true],
]));
// A broken file must not hide the working ones — the same rule as on
// Block:default and in `donut --list`.
FileSystem::write($project . '/blocks/broken.json', '{');

FileSystem::write($project . '/workflows/w.json', json_encode([
	'name' => 'w',
	'steps' => [],
]));

[, $html] = runWorkflowPresenterIn($project, [
	'action' => 'pickBlock', 'name' => 'w', 'at' => 'w.json:steps[0]',
]);

// every block is offered, and the card carries what tells them apart
Assert::contains('>jq<', $html);
Assert::contains('Filters JSON on stdin.', $html);
Assert::contains('>echo<', $html);

// the card is a link that hands the block to the step form
Assert::match('~href="[^"]*block=jq[^"]*"~', $html);
Assert::match('~href="[^"]*type=run[^"]*"~', $html);
Assert::match('~href="[^"]*at=w\.json[^"]*"~', $html);

// the card's input count is what the next page will show: echo declares
// nothing at all, cat declares no `inputs` but does read stdin. Counting
// only $block->inputs would make cat's card say "no inputs" and then open a
// form with one required field. Matched against the command, which is unique
// per fixture, so neither card can satisfy the other's assertion.
Assert::match('~<code>echo</code>[^<]*no inputs~', $html);
Assert::match('~<code>cat</code>[^<]*1 input~', $html);
Assert::match('~<code>jq</code>[^<]*1 input~', $html);

// the broken file is reported, and the working blocks are still there
Assert::contains('broken', $html);
Assert::contains('text-danger', $html);

// --- a missing blocks/ directory says when it will appear ---

$empty = TEMP_DIR . '/emptyProfile';
FileSystem::createDir($empty . '/workflows');
FileSystem::write($empty . '/workflows/w.json', json_encode(['name' => 'w', 'steps' => []]));

[, $emptyHtml] = runWorkflowPresenterIn($empty, [
	'action' => 'pickBlock', 'name' => 'w', 'at' => 'w.json:steps[0]',
]);

Assert::contains('Donut will create it when you save.', $emptyHtml);

// --- a path pointing into another workflow is a bad request ---
//
// The page only builds links, but a wrong `at` would produce links that fail
// one click later, with the message pointing at the step form instead of at
// the address that was actually wrong.

$e = Assert::exception(
	fn() => createWorkflowPresenter([], true, new Profile(\basename($project), $project))
		->run(new NetteRequest('Workflow', 'GET', [
			'action' => 'pickBlock', 'name' => 'w', 'at' => 'other.json:steps[0]',
		])),
	BadRequestException::class,
);
Assert::same(400, $e->getHttpCode());

// --- the tree's "+ step: run" goes through the picker, the other three don't ---

[, $detail] = runWorkflowPresenterIn($project, ['action' => 'detail', 'name' => 'w']);

Assert::match('~<div class="add">[^<]*<a href="[^"]*action=pickBlock~', $detail);
// The order of the query parameters is the router's here too, so this asks
// about each link rather than about one fixed sequence: no href may carry
// action=step and type=run together, whichever way round the router emits
// them. `~action=step[^"]*type=run~` assumed action came first and would
// have gone quietly green if that ever changed.
\preg_match_all('~"([^"]*action=step[^"]*)"~', $detail, $urls);
Assert::same(
	[],
	\array_values(\array_filter(
		$urls[1],
		fn(string $url): bool => \str_contains($url, 'type=run'),
	)),
	'run must not skip the picker'
);
Assert::match('~href="[^"]*type=set~', $detail, 'set still goes straight to the form');

FileSystem::delete(TEMP_DIR);
