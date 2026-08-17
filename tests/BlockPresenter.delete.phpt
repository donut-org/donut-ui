<?php

declare(strict_types=1);

use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/blockPresenter.php';

$project = TEMP_DIR . '/delete';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');

// pouzity je v workflow, volny ne.
foreach (['pouzity', 'volny'] as $name) {
	FileSystem::write($project . "/blocks/{$name}.json", json_encode([
		'name' => $name, 'command' => 'echo', 'args' => [],
	]));
}

FileSystem::write($project . '/workflows/w.json', json_encode([
	'name' => 'w',
	'steps' => [['type' => 'run', 'block' => 'pouzity']],
]));

// --- přehled ukazuje, kdo který kámen používá ---

[, $html] = runBlockPresenterIn($project, ['action' => 'default']);

// Tabulka ukazuje, které workflow kámen volá. Dřív se tu hlídalo slovo
// „používá" z věty pod nadpisem — v tabulce je z něj hlavička sloupce.
// Ptáme se proto na obsah buňky; `contains('w')` samotné nic netvrdilo,
// protože písmeno w je v HTML všude (workflow, www).
Assert::match('~<td>\s*w\s*</td>~', $html);

// --- editace volného kamene nabídne mazání ---

[, $html] = runBlockPresenterIn($project, ['action' => 'edit', 'name' => 'volny']);
Assert::contains('Smazat', $html);

// --- editace použitého kamene mazání nenabídne a řekne proč ---

[, $html] = runBlockPresenterIn($project, ['action' => 'edit', 'name' => 'pouzity']);
// dřív se cílilo na '<h2>Smazat</h2>' — ten nadpis zmizel (Task 3, karty).
// U kamene (na rozdíl od workflow) se karta „Smazat" vykresluje vždy, když
// má jméno — u použitého kamene má v těle jen větu „Nejde smazat…", ne
// mazací formulář. notContains('card border-danger', ...) by tu selhalo
// vždy, protože karta se ukazuje i pro použitý kámen — cílíme proto přímo
// na mazací formulář, který se nesmí vykreslit.
Assert::notContains('id="frm-deleteForm"', $html);
Assert::contains('používá', $html);
Assert::contains('w', $html);

// --- POST na použitý kámen se odmítne, i když tlačítko v HTML nebylo ---
// Šablona tlačítko schová, prezenter to ohlídá. Obojí schválně.

[$response, $html] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'pouzity', 'do' => 'deleteForm-submit'],
	['name' => 'pouzity', 'delete' => 'Smazat'],
);

Assert::false($response instanceof RedirectResponse);
Assert::true(is_file($project . '/blocks/pouzity.json'));

// Stránka pořád edituje "pouzity" a jeho obsah nesmí zmizet jen proto, že
// POST patřil deleteFormu, ne blockFormu — formShape() dřív reagoval na
// libovolný POST a vyrobil formulář s nula skupinami argumentů.
Assert::contains('value="pouzity"', $html);
// (skupina argumentů má od opravy I4 vedle arg-group i bootstrapí třídy,
// proto se hledá začátek seznamu tříd, ne celý atribut)
Assert::match('~<div class="arg-group\b~', $html);

// --- volný kámen se smaže a přesměruje se do přehledu ---

[$response] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'volny', 'do' => 'deleteForm-submit'],
	['name' => 'volny', 'delete' => 'Smazat'],
);

Assert::type(RedirectResponse::class, $response);
Assert::false(is_file($project . '/blocks/volny.json'));

// --- kámen, který se nedá naparsovat, jde smazat ---
// Sekce Smazat dřív seděla uvnitř {if !$error}, takže rozbitý soubor — ten,
// co nejvíc chceš odstranit — nenabídl žádnou cestu ven.

FileSystem::write($project . '/blocks/rozbity.json', 'toto neni json');

[, $html] = runBlockPresenterIn($project, ['action' => 'edit', 'name' => 'rozbity']);

// $error je nastavený (soubor se nenaparsoval)...
Assert::contains('alert-danger', $html);
// ...ale tlačítko Smazat se přesto ukáže.
Assert::contains('Smazat', $html);

FileSystem::delete(TEMP_DIR);
