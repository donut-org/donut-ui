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

// Cesta se skládá na jednom místě, a je to tohle.
Assert::same($dir . '/card-dev.json', $store->path('card-dev'));

// Uložení založí soubor, který jde hned přečíst zpátky.
$store->save(new Workflow(
	name: 'w',
	steps: [new SetStep(key: 'a', value: Template::parse('1'))],
	description: 'Popis',
));

Assert::true(\is_file($dir . '/w.json'));

$loaded = (new WorkflowParser)->parseFile($dir . '/w.json');
Assert::same('w', $loaded->name);
Assert::same('Popis', $loaded->description);
Assert::count(1, $loaded->steps);

// Uložení podruhé přepíše.
$store->save(new Workflow(name: 'w'));
Assert::same([], (new WorkflowParser)->parseFile($dir . '/w.json')->steps);

// Chybějící adresář je jiná situace než prázdný.
Assert::exception(fn() => new WorkflowStore($dir . '/neni'), ParseException::class);

FileSystem::delete(TEMP_DIR);
