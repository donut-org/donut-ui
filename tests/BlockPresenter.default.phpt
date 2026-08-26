<?php

declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/blockPresenter.php';

// --- the block overview renders even without a workflows directory ---
// loadWorkflows() protects the page from ParseException when the directory
// is missing — a fresh project might not have workflows/ at all.

$noWorkflows = TEMP_DIR . '/no-workflows';
FileSystem::createDir($noWorkflows . '/blocks');

FileSystem::write($noWorkflows . '/blocks/echo.json', json_encode([
	'name' => 'echo', 'command' => 'echo', 'args' => [],
]));

[, $html] = runBlockPresenterIn($noWorkflows, ['action' => 'default']);

Assert::contains('echo', $html);
// A block nobody uses has an empty "Used by" cell. Asking for the absence
// of the word "used by" no longer works — it became the column header.
Assert::contains('<th scope=col>Used by</th>', $html);
// The empty cell must be the one after the Command column, not just any —
// since the table also has a Description column, there are more empty
// cells in the row.
Assert::match('~<code>echo</code>\s*</td>\s*<td></td>~', $html);

// --- the block overview renders even with a broken workflow file ---
// A bad workflow must not crash the page — loadWorkflows() just skips it,
// same as a bad block in renderDefault().

$brokenWorkflow = TEMP_DIR . '/broken-workflow';
FileSystem::createDir($brokenWorkflow . '/blocks');
FileSystem::createDir($brokenWorkflow . '/workflows');

FileSystem::write($brokenWorkflow . '/blocks/echo.json', json_encode([
	'name' => 'echo', 'command' => 'echo', 'args' => [],
]));
FileSystem::write($brokenWorkflow . '/workflows/rozbite.json', 'this is not json');

[, $html] = runBlockPresenterIn($brokenWorkflow, ['action' => 'default']);

Assert::contains('echo', $html);

// --- a missing blocks directory says what to do about it ---
// M8: an empty project used to be a dead end — the message announced that
// the directory was missing and left the user there. In the GUI the answer
// is the save itself, so the message points at that, not at a shell.

$empty = TEMP_DIR . '/empty';
FileSystem::createDir($empty);

[, $html] = runBlockPresenterIn($empty, ['action' => 'default']);

Assert::contains("Blocks directory '{$empty}/blocks' does not exist.", $html);
Assert::contains('Donut will create it when you save.', $html);

// --- a broken block in the overview: the error is visible and the path to
// fixing it remains ---
// An unparsable file is the one whose user needs the path to fixing and
// deleting most. For a workflow, the previous project had to add this as an
// Important finding; for blocks, no assertion had guarded it until now.

$withBroken = TEMP_DIR . '/with-broken';
FileSystem::createDir($withBroken . '/blocks');
FileSystem::write($withBroken . '/blocks/good.json', json_encode([
	'name' => 'good', 'command' => 'echo', 'args' => [], 'description' => 'Prints text',
]));
FileSystem::write($withBroken . '/blocks/broken.json', 'this is not json');

[, $html] = runBlockPresenterIn($withBroken, ['action' => 'default']);

Assert::contains('<strong>broken</strong>', $html);
Assert::contains('class=text-danger', $html);
Assert::match('~<a href="[^"]*name=broken[^"]*"[^>]*>edit</a>~', $html);

// "edit" links must be distinguishable in a screen reader's list of links
Assert::contains('aria-label="Edit block broken"', $html);
Assert::contains('aria-label="Edit block good"', $html);

// the good block next to it stays a link to the detail
Assert::match('~<a href="[^"]*action=detail[^"]*">good</a>~', $html);

// the description sits right after the name, same as in the workflow
// table: the command alone doesn't distinguish blocks (in a real project 9
// of 15 share it), the description is what you search the overview by
Assert::contains('<th scope=col>Description</th>', $html);
Assert::match('~">good</a>\s*</td>\s*<td>\s*Prints text\s*</td>~', $html);

// the remaining headers and the command in the cell — without them, the
// mutations "rename the header" and "print something else instead of the
// command" survived
Assert::contains('<th scope=col>Name</th>', $html);
Assert::contains('<th scope=col>Command</th>', $html);
Assert::match('~<td>\s*<code>echo</code>\s*</td>~', $html);

// the table scrolls on a narrow window, it doesn't get squeezed — the same
// thing WorkflowPresenter.list.phpt guards for the sister table
Assert::contains('table-responsive', $html);

// and the broken block's message sits in the second column, same as for a
// broken workflow
Assert::match('~<strong>broken</strong>\s*</td>\s*<td>\s*<span class=text-danger>~', $html);

// the buttons have Bootstrap classes — without them they look like bare
// links in the middle of an otherwise styled page
Assert::match('~<a[^>]*class="btn btn-primary"[^>]*>\\+ new block</a>~', $html);
Assert::match('~<a[^>]*class="btn btn-primary btn-sm"[^>]*>edit</a>~', $html);

FileSystem::delete(TEMP_DIR);
