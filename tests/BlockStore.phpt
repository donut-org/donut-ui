<?php

declare(strict_types=1);

use Donut\Format\Block;
use Donut\Gui\BlockStore;
use Donut\Parser\ParseException;
use Donut\Template;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$dir = TEMP_DIR . '/blocks';
FileSystem::createDir($dir);

$store = new BlockStore($dir);

// The path is assembled in one place, and it's this one.
Assert::same($dir . '/curl-get.json', $store->path('curl-get'));

// An empty directory isn't an error — an empty project must be writable.
Assert::same([], $store->names());
Assert::false($store->exists('echo'));

// Saving creates a file that can be read straight back.
$store->save(new Block(
	name: 'echo',
	command: 'echo',
	args: [[Template::parse('{%text%}')]],
	description: 'Prints text',
));

Assert::true(\is_file($dir . '/echo.json'));
Assert::true($store->exists('echo'));
Assert::same(['echo'], $store->names());
Assert::same('Prints text', $store->get('echo')->description);

// Saving a second time overwrites.
$store->save(new Block(name: 'echo', command: 'printf', args: []));
Assert::same('printf', $store->get('echo')->command);

// A nonexistent block.
Assert::exception(fn() => $store->get('nope'), ParseException::class);

// Deleting removes the file.
$store->delete('echo');
Assert::false(\is_file($dir . '/echo.json'));
Assert::false($store->exists('echo'));

// Deleting a nonexistent one is an error, not silence — otherwise the GUI
// would report success over something that didn't happen.
Assert::exception(fn() => $store->delete('nope'), ParseException::class);

// A broken file doesn't hide the rest — same rule as `donut --list`.
FileSystem::write($dir . '/good.json', json_encode(['name' => 'good', 'command' => 'ls', 'args' => []]));
FileSystem::write($dir . '/bad.json', '{ invalid json');

$store = new BlockStore($dir);
$loaded = $store->loadAll();

Assert::same(['bad', 'good'], array_keys($loaded));
Assert::type(Block::class, $loaded['good']);
Assert::type('string', $loaded['bad']);

// A missing directory is not an error by itself: saving into it is what the
// user asked for, so the save creates it. Reading still reports it — that's
// BlockRepository's job, not the store's.
$fresh = TEMP_DIR . '/fresh/blocks';
Assert::false(\is_dir($fresh));

$store = new BlockStore($fresh);

// Nothing can exist in a directory that doesn't exist — that's an answer,
// not an error. The overwrite guard in BlockPresenter asks this before every
// save, so throwing here would close the very path the save opens.
Assert::false($store->exists('first'));

$store->save(new Block(name: 'first', command: 'ls', args: []));

Assert::true(\is_dir($fresh));
Assert::true(\is_file($fresh . '/first.json'));
Assert::same(['first'], $store->names());

FileSystem::delete(TEMP_DIR);
