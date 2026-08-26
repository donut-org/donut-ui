<?php

declare(strict_types=1);

use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

// I1: a workflow whose file stops parsing must still be fixable and
// deletable from the GUI — it's exactly the one the user needs to remove
// the most. For blocks this was already fixed once before
// (BlockPresenter.delete.phpt, "a block that fails to parse can still be
// deleted").

$project = TEMP_DIR . '/broken';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');
FileSystem::write($project . '/workflows/broken.json', '{not valid json');
FileSystem::write($project . '/workflows/w.json', json_encode(['name' => 'w', 'steps' => []]));

// --- the list offers an edit link even for a broken workflow ---

[, $list] = runWorkflowPresenterIn($project, ['action' => 'default']);

Assert::contains('class=text-danger', $list, 'a broken workflow is reported');
Assert::contains('name=broken&amp;action=edit', $list, 'the list must offer an edit link for a broken workflow');

// --- edit renders the delete form even when the file failed to parse ---

[, $html] = runWorkflowPresenterIn($project, ['action' => 'edit', 'name' => 'broken']);

Assert::contains('alert-danger', $html, 'the parse error is reported');
Assert::contains('Smazat', $html, 'delete used to sit inside {if !$error}');

// --- the breadcrumbs of a broken workflow carry the file name, not the
// word "error" ---
// Right on the page where the file failed to parse, the name from the
// address is the only thing that tells the user what failed to open.

[, $brokenDetail] = runWorkflowPresenterIn($project, ['action' => 'detail', 'name' => 'broken']);

Assert::contains('alert-danger', $brokenDetail, 'the parse error is reported');
Assert::match('~<li class="breadcrumb-item active" aria-current=page>broken</li>~', $brokenDetail);
Assert::notContains('>chyba</li>', $brokenDetail);

// --- and offer editing the header, same as the detail of a broken block ---
// The link used to sit inside {if $workflow !== null}, so the detail of a
// broken workflow showed only the message, and the path to fix it led
// nowhere.
Assert::match(
	'~<a href="[^"]*name=broken[^"]*"[^>]*>upravit hlavičku</a>~',
	$brokenDetail,
	'even a broken workflow must offer a way to fix it',
);

// --- POST really deletes the file ---
// Deleting must not depend on the file having parsed successfully: the name
// comes from the hidden field, not from the loaded workflow.

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'broken', 'do' => 'deleteWorkflowForm-submit'],
	['name' => 'broken', 'save' => 'Delete'],
);

Assert::type(RedirectResponse::class, $response);
Assert::false(\is_file($project . '/workflows/broken.json'));

// --- M6: a POST with an empty name ends in a message, not a crash ---

[$response, $html] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'w', 'do' => 'deleteWorkflowForm-submit'],
	['name' => '', 'save' => 'Delete'],
);

Assert::false($response instanceof RedirectResponse, 'nothing to delete, so nowhere to redirect either');
Assert::contains('Nothing to delete', $html);
Assert::true(\is_file($project . '/workflows/w.json'), 'an empty name must not touch anything else');


// I4: a project that has workflows/ but not blocks/ must still render the
// detail — without validation, but with the step tree and a link to the
// header. blockNames() deliberately tolerates a missing blocks directory,
// renderDetail() used to end without an <h1> on it.

$withoutBlocks = TEMP_DIR . '/without-blocks';
FileSystem::createDir($withoutBlocks . '/workflows');
FileSystem::write($withoutBlocks . '/workflows/w.json', json_encode([
	'name' => 'w',
	'steps' => [['type' => 'set', 'key' => 'a', 'value' => '1']],
]));

[, $detail] = runWorkflowPresenterIn($withoutBlocks, ['action' => 'detail', 'name' => 'w']);

Assert::contains('blocks', $detail, 'missing blocks are reported');
Assert::contains('<h1>w</h1>', $detail, 'the header renders even without blocks');
Assert::contains('upravit hlavičku', $detail, 'the link to the envelope must not disappear');
Assert::match('~<span class="badge [^"]*">set</span>~', $detail, 'the step tree renders even without validation');

FileSystem::delete(TEMP_DIR);
