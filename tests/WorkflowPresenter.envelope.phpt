<?php

declare(strict_types=1);

use Donut\Parser\WorkflowParser;
use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

$project = TEMP_DIR . '/envelope';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');

FileSystem::write($project . '/workflows/w.json', json_encode([
	'name' => 'w',
	'description' => 'Popis',
	'inputs' => ['repo' => ['required' => true, 'description' => 'Repozitář']],
	'steps' => [['type' => 'set', 'key' => 'a', 'value' => '1']],
]));

$load = fn(string $name) => (new WorkflowParser)->parseFile($project . "/workflows/{$name}.json");

// --- úprava: formulář je předvyplněný ---

[, $html] = runWorkflowPresenterIn($project, ['action' => 'edit', 'name' => 'w']);

Assert::contains('value="w"', $html);
Assert::contains('Popis', $html);
Assert::contains('repo', $html);

// --- zakládání: prázdný formulář, žádný pád ---

[, $novy] = runWorkflowPresenterIn($project, ['action' => 'edit']);

Assert::contains('<form', $novy);
Assert::notContains('Repozitář', $novy);

// --- zakládání uloží prázdné workflow ---

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'do' => 'headerForm-submit'],
	[
		'name' => 'nove',
		'description' => 'Nové',
		'inputs' => [0 => ['name' => 'x', 'required' => '1', 'default' => '', 'description' => '']],
		'save' => 'Uložit',
	],
);

Assert::type(RedirectResponse::class, $response);

$nove = $load('nove');
Assert::same('nove', $nove->name);
Assert::same('Nové', $nove->description);
Assert::same(['x'], array_keys($nove->inputs));
Assert::same([], $nove->steps, 'nové workflow vzniká prázdné');

// --- zakládání přes existující jméno NEPŘEPÍŠE ---
//
// Lekce z editace kamene, kde to byl Critical: writeFile() přepisuje bez
// ptaní a přesměrování vypadá jako úspěch.

$before = FileSystem::read($project . '/workflows/w.json');

[$response, $html] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'do' => 'headerForm-submit'],
	['name' => 'w', 'description' => 'Přepis', 'inputs' => [], 'save' => 'Uložit'],
);

Assert::false($response instanceof RedirectResponse, 'přepis se nesmí tvářit jako úspěch');
Assert::contains('existuje', $html);
Assert::same($before, FileSystem::read($project . '/workflows/w.json'), 'původní soubor musí zůstat bajt po bajtu stejný');

// --- úprava nesmí ztratit kroky ---

runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'w', 'do' => 'headerForm-submit'],
	['name' => 'w', 'description' => 'Jiný popis', 'inputs' => [], 'save' => 'Uložit'],
);

$upravene = $load('w');
Assert::same('Jiný popis', $upravene->description);
Assert::count(1, $upravene->steps, 'kroky se úpravou hlavičky nesmí ztratit');

// --- odeslání změněného jména při úpravě zapíše původní ---
//
// Lekce z editace kamene, kde to byl Important: „přejmenování" jinak objekt
// rozdvojí.

runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'w', 'do' => 'headerForm-submit'],
	['name' => 'prejmenovane', 'description' => 'X', 'inputs' => [], 'save' => 'Uložit'],
);

Assert::true(\is_file($project . '/workflows/w.json'));
Assert::false(\is_file($project . '/workflows/prejmenovane.json'), 'nesmí vzniknout druhý soubor');

// --- mazání ---

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'nove', 'do' => 'deleteWorkflowForm-submit'],
	['name' => 'nove', 'save' => 'Smazat'],
);

Assert::type(RedirectResponse::class, $response);
Assert::false(\is_file($project . '/workflows/nove.json'));

// --- mazání se nenabízí u zakládání ---

[, $novy] = runWorkflowPresenterIn($project, ['action' => 'edit']);
// obyčejné 'Smazat' by teď chytilo i accessibilní popisek tlačítka pro
// smazání řádku tabulky (Task 6) — cílíme přímo na nadpis sekce mazání workflow.
Assert::notContains('<h2>Smazat</h2>', $novy);

// --- seznam nabízí zakládání ---

[, $seznam] = runWorkflowPresenterIn($project, ['action' => 'default']);
Assert::contains('nové workflow', $seznam);

// --- zakládání bez adresáře workflows se ohlásí, nespadne ---
//
// WorkflowStore::__construct() hází ParseException, když adresář workflows
// neexistuje — na čerstvém projektu je to normální stav. headerFormSucceeded()
// to musí zachytit stejně vlídně jako WriteException, ne nechat výjimku
// propadnout jako neodchycenou.

$bezAdresare = TEMP_DIR . '/envelope-bez-workflows';
FileSystem::createDir($bezAdresare);

[$response, $html] = runWorkflowPresenterIn(
	$bezAdresare,
	['action' => 'edit', 'do' => 'headerForm-submit'],
	['name' => 'nove', 'description' => '', 'inputs' => [], 'save' => 'Uložit'],
);

Assert::false($response instanceof RedirectResponse, 'chybějící adresář nesmí skončit přesměrováním');
Assert::contains('neexistuje', $html);
// M8: hláška musí říct, co s tím — jinak je prázdný projekt slepá ulička.
Assert::contains('mkdir workflows', $html);

FileSystem::delete(TEMP_DIR);
