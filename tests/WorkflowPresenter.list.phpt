<?php

declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

$dir = TEMP_DIR . '/project';
FileSystem::createDir($dir . '/workflows');
FileSystem::write($dir . '/workflows/sync.json', \json_encode([
	'name' => 'sync',
	'description' => 'Synchronizes the card',
	'steps' => [],
]));
FileSystem::write($dir . '/workflows/broken.json', '{not valid json');

[, $html] = runWorkflowPresenterIn($dir, ['action' => 'default']);

// column headers
Assert::contains('<th scope=col>Name</th>', $html);
Assert::contains('<th scope=col>Description</th>', $html);

// the table scrolls on a narrow window, it doesn't get squeezed
Assert::contains('table-responsive', $html);

// a valid workflow: the name is a link to the detail, the description is visible
Assert::match('~<a href="[^"]*action=detail[^"]*">sync</a>~', $html);
Assert::contains('Synchronizes the card', $html);

// a broken workflow: the name isn't a link to the detail, the error is visible
Assert::notMatch('~<a href="[^"]*">broken</a>~', $html);
Assert::contains('<strong>broken</strong>', $html);
Assert::contains('class=text-danger', $html);

// and importantly: even a broken row has a way to fix and delete it
Assert::match('~<a href="[^"]*name=broken[^"]*"[^>]*>edit</a>~', $html);
Assert::match('~<a href="[^"]*name=sync[^"]*"[^>]*>edit</a>~', $html);

// "edit" links must be distinguishable to a screen reader
Assert::contains('aria-label="Edit workflow broken"', $html);
Assert::contains('aria-label="Edit workflow sync"', $html);

// buttons have Bootstrap classes — otherwise they look like bare links
// in the middle of an otherwise styled page
Assert::match('~<a[^>]*class="btn btn-primary"[^>]*>\\+ new workflow</a>~', $html);
Assert::match('~<a[^>]*class="btn btn-primary btn-sm"[^>]*>edit</a>~', $html);

// the old list is gone
Assert::notContains('<ul>', $html);
