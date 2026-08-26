<?php

declare(strict_types=1);

use Donut\Format\SetStep;
use Donut\Format\Workflow;
use Donut\Gui\WorkflowStore;
use Donut\Parser\ParseException;
use Donut\Parser\WorkflowParser;
use Donut\Template;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$dir = TEMP_DIR . '/workflows';
FileSystem::createDir($dir);

$store = new WorkflowStore($dir);

// The path is assembled in one place, and it's this one.
Assert::same($dir . '/card-dev.json', $store->path('card-dev'));

// Saving creates a file that can be read straight back.
$store->save(new Workflow(
	name: 'w',
	steps: [new SetStep(key: 'a', value: Template::parse('1'))],
	description: 'Description',
));

Assert::true(\is_file($dir . '/w.json'));

$loaded = (new WorkflowParser)->parseFile($dir . '/w.json');
Assert::same('w', $loaded->name);
Assert::same('Description', $loaded->description);
Assert::count(1, $loaded->steps);

// Saving a second time overwrites.
$store->save(new Workflow(name: 'w'));
Assert::same([], (new WorkflowParser)->parseFile($dir . '/w.json')->steps);

// A missing directory is not an error by itself: saving into it is what the
// user asked for, so the save creates it. Reading still reports it — that's
// WorkflowRepository's job, not the store's.
$fresh = TEMP_DIR . '/fresh/workflows';
Assert::false(\is_dir($fresh));

$freshStore = new WorkflowStore($fresh);
$freshStore->save(new Workflow(name: 'first'));

Assert::true(\is_dir($fresh));
Assert::true(\is_file($fresh . '/first.json'));
Assert::true($freshStore->exists('first'));

// --- exists and delete ---

Assert::true($store->exists('w'));
Assert::false($store->exists('gone'));

$store->delete('w');

Assert::false(\is_file($dir . '/w.json'));
Assert::false($store->exists('w'));

// Deleting a nonexistent one is an error, not silence — otherwise the GUI
// would report success over something that didn't happen.
Assert::exception(fn() => $store->delete('gone'), ParseException::class);

FileSystem::delete(TEMP_DIR);
