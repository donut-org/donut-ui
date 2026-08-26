<?php

declare(strict_types=1);

use Donut\Parser\WorkflowParser;
use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

$project = TEMP_DIR . '/controls';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');

FileSystem::write($project . '/blocks/echo.json', json_encode([
	'name' => 'echo', 'command' => 'echo', 'args' => [],
]));

$write = fn(array $steps) => FileSystem::write(
	$project . '/workflows/w.json',
	json_encode(['name' => 'w', 'steps' => $steps]),
);

$steps = fn(): array => (new WorkflowParser)->parseFile($project . '/workflows/w.json')->steps;

// an empty if — nothing can be added to its branches today, because they
// don't render at all
$write([
	['type' => 'set', 'key' => 'a', 'value' => '1'],
	['type' => 'if', 'condition' => ['left' => '{%x%}', 'op' => 'not_empty'], 'then' => []],
	['type' => 'set', 'key' => 'b', 'value' => '2'],
]);

// --- the overview offers controls ---

[, $html] = runWorkflowPresenterIn($project, ['action' => 'detail', 'name' => 'w']);

Assert::contains('w.json:steps[0]', $html);

// Deleting goes through POST, not a link — a GET that changes a file would
// get caught by the browser's prefetcher. We assert on the shape of the
// markup, not on some string being missing from the HTML: an empty page
// would satisfy that assertion too.
Assert::match('~<form[^>]+method=post[^>]*>\s*<input[^>]+name=at[^>]+value="w\.json:steps\[0]"~', $html);
Assert::notContains('<a href="?do=stepTree-deleteStep', $html);

// The first step has no up arrow, the last has no down arrow. Three steps →
// twice each.
Assert::same(2, substr_count($html, 'do=stepTree-moveUp'));
Assert::same(2, substr_count($html, 'do=stepTree-moveDown'));

// An empty then branch renders even so — otherwise Task 6's "+ step"
// couldn't be added to it. Its path never appears in the HTML (an empty list
// has no controls), so we assert on the branch label instead.
Assert::match('~<h3 class="branch-label[^"]*"><span[^>]*>then</span></h3>~', $html);
Assert::match('~<h3 class="branch-label[^"]*"><span[^>]*>else</span></h3>~', $html);

// --- move down ---

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'detail', 'name' => 'w', 'do' => 'stepTree-moveDown'],
	['at' => 'w.json:steps[0]'],
);

Assert::type(RedirectResponse::class, $response);
Assert::same('if', $steps()[0] instanceof Donut\Format\IfStep ? 'if' : 'other');
Assert::same('a', $steps()[1]->key);

// --- move back up ---

runWorkflowPresenterIn(
	$project,
	['action' => 'detail', 'name' => 'w', 'do' => 'stepTree-moveUp'],
	['at' => 'w.json:steps[1]'],
);

Assert::same('a', $steps()[0]->key);

// --- deleting ---

runWorkflowPresenterIn(
	$project,
	['action' => 'detail', 'name' => 'w', 'do' => 'stepTree-deleteStep'],
	['at' => 'w.json:steps[0]'],
);

Assert::count(2, $steps());
Assert::type(Donut\Format\IfStep::class, $steps()[0]);

// --- an invalid path doesn't crash with HTTP 500 ---

[, $html] = runWorkflowPresenterIn(
	$project,
	['action' => 'detail', 'name' => 'w', 'do' => 'stepTree-deleteStep'],
	['at' => 'w.json:steps[99]'],
);

Assert::count(2, $steps());

// An invalid path must be reported to the user, not just silently do
// nothing — before the step tree became a component, no test checked this
// message.
Assert::contains('alert-danger', $html);
Assert::contains('Step "w.json:steps[99]" does not exist.', $html);

[, $html] = runWorkflowPresenterIn(
	$project,
	['action' => 'detail', 'name' => 'w', 'do' => 'stepTree-deleteStep'],
	['at' => 'nonsense'],
);

Assert::contains('alert-danger', $html);
Assert::contains('"nonsense" is not a step path.', $html);

// --- a path from a different workflow is rejected, not applied as a position in this one ---

[, $html] = runWorkflowPresenterIn(
	$project,
	['action' => 'detail', 'name' => 'w', 'do' => 'stepTree-deleteStep'],
	['at' => 'other.json:steps[0]'],
);

Assert::count(2, $steps());

Assert::count(2, $steps());

Assert::contains('alert-danger', $html);
Assert::contains('Path "other.json:steps[0]" does not belong to workflow "w".', $html);

// --- an invalid workflow still gets saved: validation doesn't block ---
//
// The run step references a block that doesn't exist. Moving it must not be
// rejected.

$write([
	['type' => 'run', 'block' => 'missing'],
	['type' => 'set', 'key' => 'a', 'value' => '1'],
]);

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'detail', 'name' => 'w', 'do' => 'stepTree-moveDown'],
	['at' => 'w.json:steps[0]'],
);

Assert::type(RedirectResponse::class, $response);
Assert::same('a', $steps()[0]->key);

// --- the × button on a step with a subtree offers a confirmation, otherwise
// not ---
// It's the only safeguard against deleting a subtree — without it, ×
// would delete nested steps without warning, just as silently as that one
// step alone.

$write([
	['type' => 'set', 'key' => 'a', 'value' => '1'],
	['type' => 'if', 'condition' => ['left' => '{%x%}', 'op' => 'not_empty'], 'then' => [
		['type' => 'set', 'key' => 'b', 'value' => '2'],
	]],
]);

[, $html] = runWorkflowPresenterIn($project, ['action' => 'detail', 'name' => 'w']);

Assert::contains(
	"onclick=\"return confirm(&apos;Delete 1 nested step?&apos;)\"",
	$html,
	'a step with a subtree must offer a delete confirmation',
);
Assert::same(1, substr_count($html, 'confirm('), 'a step without children (set a, set b) must not offer a confirmation at all');

FileSystem::delete(TEMP_DIR);
