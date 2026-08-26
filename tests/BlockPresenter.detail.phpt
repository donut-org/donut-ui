<?php

declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/blockPresenter.php';

$dir = TEMP_DIR . '/project';
FileSystem::createDir($dir . '/blocks');
FileSystem::createDir($dir . '/workflows');

FileSystem::write($dir . '/blocks/curl-get.json', \json_encode([
	'name' => 'curl-get',
	'description' => 'Downloads the address',
	'command' => 'curl',
	'args' => [['-sS'], ['-H', '{%header%}'], ['{%url%}']],
	'inputs' => [
		'url' => ['required' => true, 'description' => 'Full address'],
		'header' => ['required' => false, 'default' => 'Accept: */*'],
	],
	'stdin' => ['required' => false, 'description' => 'Request body'],
	'timeout' => 30,
	'allow_failure' => [0, 22],
]));

FileSystem::write($dir . '/workflows/sync.json', \json_encode([
	'name' => 'sync',
	'steps' => [['type' => 'run', 'block' => 'curl-get', 'in' => ['url' => 'x']]],
]));

[, $html] = runBlockPresenterIn($dir, ['action' => 'detail', 'name' => 'curl-get']);

// command and description
// "curl" alone wouldn't catch this assertion — the block is named curl-get,
// so that word is in the HTML from <h1>, from the breadcrumbs and from the
// links, and the command could vanish from the detail entirely without the
// suite noticing.
Assert::contains('command: <code>curl</code>', $html);
Assert::contains('Downloads the address', $html);

// arguments — today's overview doesn't list them at all, the detail must
Assert::contains('-sS', $html);
Assert::contains('-H', $html);

// inputs, with required-ness and default value too
Assert::contains('url', $html);
Assert::contains('required', $html);
Assert::contains('Full address', $html);
Assert::contains('Accept: */*', $html);

// stdin, timeout, allow_failure
Assert::contains('Request body', $html);
Assert::contains('30', $html);
Assert::contains('22', $html);

// who uses the block
Assert::contains('sync', $html);

// path to editing
Assert::match('~<a href="[^"]*action=edit[^"]*"[^>]*>edit</a>~', $html);


// --- a broken block must be possible to open ---
FileSystem::write($dir . '/blocks/broken.json', '{not valid json');
FileSystem::write($dir . '/workflows/fix.json', \json_encode([
	'name' => 'fix',
	'steps' => [['type' => 'run', 'block' => 'broken', 'in' => []]],
]));

[, $broken] = runBlockPresenterIn($dir, ['action' => 'detail', 'name' => 'broken']);

Assert::contains('alert-danger', $broken);
Assert::match('~<a href="[^"]*action=edit[^"]*"[^>]*>edit</a>~', $broken);

// and importantly: even for a broken block you can see who uses it —
// $usedBy is computed from the workflows, not the block, and right before
// fixing or deleting it, that's the most important information on the
// page
Assert::contains('<h2>Used by</h2>', $broken);
Assert::contains('<li>fix</li>', $broken);
