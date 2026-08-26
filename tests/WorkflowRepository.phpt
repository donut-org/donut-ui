<?php

declare(strict_types=1);

use Donut\Format\Workflow;
use Donut\Gui\WorkflowRepository;
use Donut\Parser\ParseException;
use Donut\Parser\NotFoundException;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$dir = TEMP_DIR . '/workflows';
FileSystem::createDir($dir);

FileSystem::write($dir . '/b.json', json_encode(['name' => 'b', 'steps' => []]));
FileSystem::write($dir . '/a.json', json_encode(['name' => 'a', 'steps' => []]));
FileSystem::write($dir . '/broken.json', '{ invalid json');

$repo = new WorkflowRepository($dir);

// Sorted by name, not by order in the directory.
Assert::same(['a', 'b', 'broken'], $repo->getNames());

Assert::true($repo->has('a'));
Assert::false($repo->has('nope'));
Assert::same('a', $repo->get('a')->name);

Assert::exception(
	fn() => $repo->get('nope'),
	ParseException::class,
	'Workflow "nope" does not exist. Searched in: ' . $dir,
);

// A broken file doesn't hide the rest — same rule as BlockRepository
// and `donut --list`.
$loaded = $repo->loadAll();
Assert::same(['a', 'b', 'broken'], array_keys($loaded));
Assert::type(Workflow::class, $loaded['a']);
Assert::type(Workflow::class, $loaded['b']);
Assert::type('string', $loaded['broken']);

// An existing but empty directory — a valid state, no error.
$empty = TEMP_DIR . '/empty';
FileSystem::createDir($empty);
Assert::noError(fn() => new WorkflowRepository($empty));
Assert::same([], (new WorkflowRepository($empty))->loadAll());

// A missing directory is a different situation than an empty one — it must
// throw, not return []. The message is actionable: otherwise there's no way
// forward from an empty project. It points at saving rather than at `mkdir`,
// because in the GUI the save is what creates the directory — the CLI keeps
// MissingDir::hint().
Assert::exception(
	fn() => new WorkflowRepository($dir . '/gone'),
	ParseException::class,
	"Workflows directory '{$dir}/gone' does not exist. Donut will create it when you save.",
);

// A workflow that isn't there is a different failure from one whose file is
// broken: the first is a missing resource (the GUI answers 404), the second
// is a resource that exists and won't parse. NotFoundException extends
// ParseException, so everything that caught the general kind still does.
Assert::exception(
	fn() => (new WorkflowRepository($dir))->get('nope'),
	NotFoundException::class,
	"Workflow \"nope\" does not exist. Searched in: {$dir}",
);

FileSystem::write($dir . '/broken.json', '{ not json');
$broken = Assert::exception(fn() => (new WorkflowRepository($dir))->get('broken'), ParseException::class);
Assert::false($broken instanceof NotFoundException, 'a broken file is a resource that exists, not a missing one');

FileSystem::delete(TEMP_DIR);
