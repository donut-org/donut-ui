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

// Cesta se skládá na jednom místě, a je to tohle.
Assert::same($dir . '/curl-get.json', $store->path('curl-get'));

// Prázdný adresář není chyba — do prázdného projektu se musí dát psát.
Assert::same([], $store->names());
Assert::false($store->exists('echo'));

// Uložení založí soubor, který jde hned přečíst zpátky.
$store->save(new Block(
	name: 'echo',
	command: 'echo',
	args: [[Template::parse('{%text%}')]],
	description: 'Vypíše text',
));

Assert::true(\is_file($dir . '/echo.json'));
Assert::true($store->exists('echo'));
Assert::same(['echo'], $store->names());
Assert::same('Vypíše text', $store->get('echo')->description);

// Uložení podruhé přepíše.
$store->save(new Block(name: 'echo', command: 'printf', args: []));
Assert::same('printf', $store->get('echo')->command);

// Neexistující kámen.
Assert::exception(fn() => $store->get('nope'), ParseException::class);

// Smazání odstraní soubor.
$store->delete('echo');
Assert::false(\is_file($dir . '/echo.json'));
Assert::false($store->exists('echo'));

// Smazání neexistujícího je chyba, ne ticho — jinak by GUI hlásilo úspěch
// nad něčím, co se nestalo.
Assert::exception(fn() => $store->delete('nope'), ParseException::class);

// Vadný soubor nezastíní ostatní — stejné pravidlo jako u `donut --list`.
FileSystem::write($dir . '/dobry.json', json_encode(['name' => 'dobry', 'command' => 'ls', 'args' => []]));
FileSystem::write($dir . '/vadny.json', '{ neplatny json');

$store = new BlockStore($dir);
$loaded = $store->loadAll();

Assert::same(['dobry', 'vadny'], array_keys($loaded));
Assert::type(Block::class, $loaded['dobry']);
Assert::type('string', $loaded['vadny']);

// Chybějící adresář je jiná situace než prázdný.
Assert::exception(fn() => new BlockStore($dir . '/neni'), ParseException::class);

FileSystem::delete(TEMP_DIR);
