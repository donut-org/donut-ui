<?php

declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/blockPresenter.php';

// --- přehled kamenů se vykreslí i bez adresáře workflows ---
// loadWorkflows() chrání stránku před ParseException, když adresář chybí —
// čerstvý projekt nemusí mít workflows/ vůbec.

$noWorkflows = TEMP_DIR . '/no-workflows';
FileSystem::createDir($noWorkflows . '/blocks');

FileSystem::write($noWorkflows . '/blocks/echo.json', json_encode([
	'name' => 'echo', 'command' => 'echo', 'args' => [],
]));

[, $html] = runBlockPresenterIn($noWorkflows, ['action' => 'default']);

Assert::contains('echo', $html);
Assert::notContains('používá', $html);

// --- přehled kamenů se vykreslí i s rozbitým workflow souborem ---
// Vadné workflow nesmí shodit stránku — loadWorkflows() ho jen přeskočí,
// stejně jako vadný kámen v renderDefault().

$brokenWorkflow = TEMP_DIR . '/broken-workflow';
FileSystem::createDir($brokenWorkflow . '/blocks');
FileSystem::createDir($brokenWorkflow . '/workflows');

FileSystem::write($brokenWorkflow . '/blocks/echo.json', json_encode([
	'name' => 'echo', 'command' => 'echo', 'args' => [],
]));
FileSystem::write($brokenWorkflow . '/workflows/rozbite.json', 'toto neni json');

[, $html] = runBlockPresenterIn($brokenWorkflow, ['action' => 'default']);

Assert::contains('echo', $html);

FileSystem::delete(TEMP_DIR);
