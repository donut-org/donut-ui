<?php

declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';
require __DIR__ . '/inc/blockPresenter.php';

$dir = TEMP_DIR . '/project';
FileSystem::createDir($dir . '/workflows');
FileSystem::write($dir . '/workflows/w.json', \json_encode([
	'name' => 'w',
	'inputs' => ['repo' => ['required' => true, 'description' => 'Repository']],
	'steps' => [],
]));

[, $html] = runWorkflowPresenterIn($dir, ['action' => 'edit', 'name' => 'w']);

// the header is what tells the user what belongs in which field
Assert::contains('<th scope=col>Jméno</th>', $html);
Assert::contains('<th scope=col>Povinný</th>', $html);
Assert::contains('<th scope=col>Výchozí</th>', $html);
Assert::contains('<th scope=col>Popis</th>', $html);

// markup that rows.js reaches into
Assert::contains('<tbody id=inputs>', $html);
Assert::match('~<tr class=js-row>~', $html);
Assert::contains('js-del-row', $html);
Assert::contains('data-add=inputs', $html);

// the delete button has an accessible name too — without aria-label a
// screen reader would just hear "button ×"
Assert::contains('js-del-row" aria-label="Smazat řádek"', $html);

// the old .row class is gone — it would collide with the Bootstrap grid
Assert::notMatch('~<div class=row[ >]~', $html);

// the header names the cell, not the field inside it — a screen reader
// needs the aria-label. The headers stay as they are (assertions on them
// are above).
Assert::match('~name="inputs\[0\]\[name\]"[^>]*aria-label="Name"~', $html);
Assert::match('~name="inputs\[0\]\[required\]"[^>]*aria-label="Required"~', $html);
Assert::match('~name="inputs\[0\]\[default\]"[^>]*aria-label="Default"~', $html);
Assert::match('~name="inputs\[0\]\[description\]"[^>]*aria-label="Description"~', $html);

// on a narrow window the table scrolls, the columns don't get squeezed
Assert::contains('<div class=table-responsive>', $html);

// values from the file stay in the table
Assert::match('~name="inputs\[0\]\[name\]"[^>]*value="repo"~', $html);
Assert::match('~name="inputs\[0\]\[description\]"[^>]*value="Repository"~', $html);

// help text under the table
Assert::contains('--jmeno=hodnota', $html);
Assert::contains('když ji volající nepředá', $html);


// --- the step page: the in and out tables -----------------------------

$step = TEMP_DIR . '/step';
FileSystem::createDir($step . '/blocks');
FileSystem::createDir($step . '/workflows');
FileSystem::write($step . '/blocks/jq.json', \json_encode([
	'name' => 'jq',
	'command' => 'jq',
	'inputs' => ['filter' => ['required' => true]],
	'stdin' => ['required' => true],
]));
FileSystem::write($step . '/workflows/w.json', \json_encode([
	'name' => 'w',
	'steps' => [[
		'type' => 'run',
		'block' => 'jq',
		'in' => ['filter' => '.id'],
		'out' => ['result' => 'id'],
	]],
]));

[, $stepHtml] = runWorkflowPresenterIn($step, [
	'action' => 'step',
	'name' => 'w',
	'at' => 'w.json:steps[0]',
]);

Assert::contains('<th scope=col>Vstup kamene</th>', $stepHtml);
Assert::contains('<th scope=col>Co z kamene</th>', $stepHtml);
Assert::contains('<th scope=col>Pod jakým klíčem do mapy</th>', $stepHtml);

// the literal {%klíč%} in the header — Latte can only output it via {='…'}
Assert::contains('&#123;%klíč%}', $stepHtml);

Assert::contains('<tbody id=in>', $stepHtml);
Assert::contains('<tbody id=out>', $stepHtml);
Assert::contains('data-add=in', $stepHtml);
Assert::contains('data-add=out', $stepHtml);
Assert::notMatch('~<div class=row[ >]~', $stepHtml);

// the delete button has an accessible name too — without aria-label a
// screen reader would just hear "button ×"; both tables have two rows each
// (the filled one plus an extra empty one), so four delete buttons total
Assert::same(4, \substr_count($stepHtml, 'js-del-row" aria-label="Smazat řádek"'));

// the fields of both tables have an accessible name, and both tables
// scroll on a narrow window
Assert::match('~name="in\[0\]\[key\]"[^>]*aria-label="Block input"~', $stepHtml);
Assert::match('~name="in\[0\]\[value\]"[^>]*aria-label="Value"~', $stepHtml);
Assert::match('~name="out\[0\]\[channel\]"[^>]*aria-label="Block output"~', $stepHtml);
Assert::match('~name="out\[0\]\[value\]"[^>]*aria-label="Map key"~', $stepHtml);
Assert::same(2, \substr_count($stepHtml, '<div class=table-responsive>'));

// the step's values stay in the tables
Assert::match('~name="in\[0\]\[key\]"[^>]*value="filter"~', $stepHtml);
Assert::match('~name="out\[0\]\[value\]"[^>]*value="id"~', $stepHtml);


// --- the block page: the inputs table -----------------------------------
// Task 6 changed three templates, not two. Without this block, the suite
// would pass even with the markup in Block/edit.latte completely broken.

$block = TEMP_DIR . '/block';
FileSystem::createDir($block . '/blocks');
FileSystem::write($block . '/blocks/k.json', \json_encode([
	'name' => 'k',
	'command' => 'echo',
	'args' => [['-n']],
	'inputs' => ['text' => ['required' => true, 'description' => 'What to print']],
]));

[, $blockHtml] = runBlockPresenterIn($block, ['action' => 'edit', 'name' => 'k']);

// the header is what tells the user what belongs in which field
Assert::contains('<th scope=col>Name</th>', $blockHtml);
Assert::contains('<th scope=col>Required</th>', $blockHtml);
Assert::contains('<th scope=col>Default</th>', $blockHtml);
Assert::contains('<th scope=col>Description</th>', $blockHtml);

// markup that rows.js reaches into
Assert::contains('<tbody id=inputs>', $blockHtml);
Assert::match('~<tr class=js-row>~', $blockHtml);
Assert::contains('js-del-row', $blockHtml);
Assert::contains('data-add=inputs', $blockHtml);

// the delete button has an accessible name too — without aria-label a
// screen reader would just hear "button ×"
Assert::contains('js-del-row" aria-label="Delete row"', $blockHtml);

// the old .row class is gone — it would collide with the Bootstrap grid
Assert::notMatch('~<div class=row[ >]~', $blockHtml);

// the fields have an accessible name, and the table scrolls on a narrow window
Assert::match('~name="inputs\[0\]\[name\]"[^>]*aria-label="Name"~', $blockHtml);
Assert::match('~name="inputs\[0\]\[required\]"[^>]*aria-label="Required"~', $blockHtml);
Assert::match('~name="inputs\[0\]\[default\]"[^>]*aria-label="Default"~', $blockHtml);
Assert::match('~name="inputs\[0\]\[description\]"[^>]*aria-label="Description"~', $blockHtml);
Assert::contains('<div class=table-responsive>', $blockHtml);

// values from the file stay in the table
Assert::match('~name="inputs\[0\]\[name\]"[^>]*value="text"~', $blockHtml);
Assert::match('~name="inputs\[0\]\[description\]"[^>]*value="What to print"~', $blockHtml);

// help text under the table
Assert::contains('--name=value', $blockHtml);
Assert::contains('the caller does not pass one', $blockHtml);

// arguments of one group stand next to each other and the group is visible
// as a whole; without w-auto, form-control would turn them into a vertical
// column spanning the full width
Assert::match('~<div class="arg-group[^"]*\bd-flex\b[^"]*"~', $blockHtml);
Assert::match('~<div class="arg-group[^"]*\bborder\b[^"]*"~', $blockHtml);
Assert::match('~name="args\[0\]\[0\]"[^>]*class="form-control w-auto"~', $blockHtml);


// --- forms come from FormFactory ----------------------------------------
// FormFactory.phpt tests the factory in isolation and asserts nothing about
// the real pages. Without these assertions, the suite would pass even if all
// five forms reverted to plain new Form.

// WorkflowPresenter: header (text, checkbox, submit)
Assert::contains('class="form-control"', $html);
Assert::contains('class="form-check-input"', $html);
Assert::contains('class="btn btn-primary"', $html);

// WorkflowPresenter: step (plus a dropdown)
Assert::contains('class="form-control"', $stepHtml);
Assert::contains('class="form-select"', $stepHtml);
Assert::contains('class="btn btn-primary"', $stepHtml);

// BlockPresenter: block
Assert::contains('class="form-control"', $blockHtml);
Assert::contains('class="form-check-input"', $blockHtml);
Assert::contains('class="btn btn-primary"', $blockHtml);

// the radios ("Allowed failure") are on both pages and must have the
// class — without it they look unfinished in the middle of an otherwise
// Bootstrap page
Assert::notMatch('~<input type="radio"(?![^>]*form-check-input)~', $blockHtml);
Assert::notMatch('~<input type="radio"(?![^>]*form-check-input)~', $stepHtml);
Assert::contains('<input type="radio"', $blockHtml);
Assert::contains('<input type="radio"', $stepHtml);

// a custom class doesn't get overwritten from the prototype — the delete
// button asked for btn-danger when it was created, and the factory leaves
// it alone
Assert::contains('btn btn-danger', $html);
Assert::contains('btn btn-danger', $blockHtml);
