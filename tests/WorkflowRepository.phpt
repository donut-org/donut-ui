<?php

declare(strict_types=1);

use Donut\Format\Workflow;
use Donut\Gui\WorkflowRepository;
use Donut\Parser\ParseException;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$dir = TEMP_DIR . '/workflows';
FileSystem::createDir($dir);

FileSystem::write($dir . '/b.json', json_encode(['name' => 'b', 'steps' => []]));
FileSystem::write($dir . '/a.json', json_encode(['name' => 'a', 'steps' => []]));
FileSystem::write($dir . '/broken.json', '{ neplatny json');

$repo = new WorkflowRepository($dir);

// Řazení podle jména, ne podle pořadí v adresáři.
Assert::same(['a', 'b', 'broken'], $repo->getNames());

Assert::true($repo->has('a'));
Assert::false($repo->has('nope'));
Assert::same('a', $repo->get('a')->name);

Assert::exception(
	fn() => $repo->get('nope'),
	ParseException::class,
	'Workflow "nope" neexistuje. Hledal jsem v: ' . $dir,
);

// Vadný soubor nezastíní ostatní — stejné pravidlo jako u BlockRepository
// a `donut --list`.
$loaded = $repo->loadAll();
Assert::same(['a', 'b', 'broken'], array_keys($loaded));
Assert::type(Workflow::class, $loaded['a']);
Assert::type(Workflow::class, $loaded['b']);
Assert::type('string', $loaded['broken']);

// Existující, ale prázdný adresář — validní stav, žádná chyba.
$empty = TEMP_DIR . '/prazdne';
FileSystem::createDir($empty);
Assert::noError(fn() => new WorkflowRepository($empty));
Assert::same([], (new WorkflowRepository($empty))->loadAll());

// Chybějící adresář je jiná situace než prázdný — musí hodit, ne vrátit [].
// Hláška je návodná: od prázdného projektu se jinak bez shellu nikam nedojde
// a GUI adresáře vědomě nezakládá.
Assert::exception(
	fn() => new WorkflowRepository($dir . '/chybi'),
	ParseException::class,
	"Adresář s workflow '{$dir}/chybi' neexistuje. Donut ho sám nezaloží — vytvoř ho příkazem `mkdir -p {$dir}/chybi`.",
);

FileSystem::delete(TEMP_DIR);
