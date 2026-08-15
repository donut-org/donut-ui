<?php

declare(strict_types=1);

use Donut\Parser\WorkflowParser;
use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

// I2: formulář hlavičky se sestavuje i při POSTu, který mu nepatří — dřív se
// v takovém případě překreslil prázdný a „Uložit" zapsalo description: null
// a inputs: []. Otázka nezní „je to POST?", ale „patří ten POST tomuhle
// formuláři?".

$project = TEMP_DIR . '/header';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');

FileSystem::write($project . '/workflows/w.json', json_encode([
	'name' => 'w',
	'description' => 'Duležitý popis',
	'inputs' => ['repo' => ['required' => true, 'description' => 'Repozitář']],
	'steps' => [['type' => 'set', 'key' => 'a', 'value' => '1']],
]));

// --- cizí POST (neúspěšné mazání) nesmí formulář hlavičky vyprázdnit ---

[$response, $html] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'w', 'do' => 'deleteWorkflowForm-submit'],
	['name' => '', 'save' => 'Smazat'],
);

Assert::false($response instanceof RedirectResponse, 'mazání selhalo, stránka se překreslila');
Assert::contains('value="w"', $html, 'jméno drží setDefaultValue()');
Assert::contains('Duležitý popis', $html, 'popis se nesmí ztratit jen proto, že POST patřil jinému formuláři');
Assert::contains('value="repo"', $html, 'vstupy se nesmí ztratit');
Assert::contains('Repozitář', $html);


// I3: tvar kontejneru `inputs` se při POSTu odvozuje z došlých dat, ne
// z počtu vstupů načteného workflow. JS řádky nikdy nepřečísluje (kontrakt
// z rows.latte), takže indexy můžou mít díry — kontejner, který pro došlý
// index nevznikne, znamená tiše ztracený vstup a redirect k nerozeznání
// od úspěchu.

$load = fn(string $name) => (new WorkflowParser)->parseFile($project . "/workflows/{$name}.json");

// --- zakládání se vstupy na indexech 0 a 3 uloží oba ---
// Přesně takový POST vyrábí rows.latte po smazání prostředního řádku.

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'do' => 'headerForm-submit'],
	[
		'name' => 'diry',
		'description' => 'Se dvěma vstupy',
		'inputs' => [
			0 => ['name' => 'a', 'required' => '1', 'default' => '', 'description' => ''],
			3 => ['name' => 'c', 'required' => '', 'default' => '', 'description' => ''],
		],
		'save' => 'Uložit',
	],
);

Assert::type(RedirectResponse::class, $response);
Assert::same(['a', 'c'], array_keys($load('diry')->inputs), 'vstup na indexu s dírou se nesmí ztratit');

// --- úprava: vstup na indexu vyšším, než kolik jich workflow má ---

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'w', 'do' => 'headerForm-submit'],
	[
		'name' => 'w',
		'description' => 'Duležitý popis',
		'inputs' => [
			0 => ['name' => 'repo', 'required' => '1', 'default' => '', 'description' => 'Repozitář'],
			5 => ['name' => 'novy', 'required' => '', 'default' => '', 'description' => ''],
		],
		'save' => 'Uložit',
	],
);

Assert::type(RedirectResponse::class, $response);
Assert::same(['repo', 'novy'], array_keys($load('w')->inputs), 'přidaný vstup nesmí zmizet jen proto, že má vyšší index');

// --- GET vezme řádky z workflow, ne z (prázdného) POSTu ---
// Bez brány na cizí signál by getPost() vrátil [] a kontejner by dostal
// jediný řádek — druhý vstup by se do formuláře vůbec nedostal.

[, $html] = runWorkflowPresenterIn($project, ['action' => 'edit', 'name' => 'w']);

Assert::contains('value="repo"', $html);
Assert::contains('value="novy"', $html, 'oba vstupy musí mít svůj řádek');

// --- N1: GET s `do=headerForm-submit` v adrese je pořád GET ---
// Ručně složená adresa (nebo záložka z doby před přesměrováním) nese signál,
// ale žádná data — getPost() vrátí [], což není null. Bez testu na HTTP metodu
// se setDefaults() přeskočí, formulář se vykreslí prázdný a „Uložit" zapíše
// description: null a inputs: [].

[, $get] = runWorkflowPresenterIn($project, ['action' => 'edit', 'name' => 'w', 'do' => 'headerForm-submit']);

Assert::contains('value="w"', $get, 'jméno drží setDefaultValue()');
Assert::contains('Duležitý popis', $get, 'popis se nesmí ztratit — GET nic neodeslal');
Assert::contains('value="repo"', $get, 'vstupy se nesmí ztratit');
Assert::contains('value="novy"', $get);

FileSystem::delete(TEMP_DIR);
