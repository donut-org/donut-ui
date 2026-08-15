<?php

declare(strict_types=1);

use Donut\Parser\BlockParser;
use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/blockPresenter.php';

$project = TEMP_DIR . '/edit';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');

FileSystem::write($project . '/blocks/echo.json', json_encode([
	'name' => 'echo',
	'description' => 'Vypíše text',
	'command' => 'echo',
	'args' => [['{%text%}']],
	'inputs' => ['text' => ['required' => true]],
	'timeout' => 5,
]));

// --- editace existujícího: formulář je předvyplněný ---

[, $html] = runBlockPresenterIn($project, ['action' => 'edit', 'name' => 'echo']);

Assert::contains('value="echo"', $html);
Assert::contains('Vypíše text', $html);
Assert::contains('{%text%}', $html);
Assert::contains('value="5"', $html);

// --- zakládání nového: prázdný formulář, žádný pád ---

[, $new] = runBlockPresenterIn($project, ['action' => 'edit']);

Assert::contains('<form', $new);
Assert::notContains('Vypíše text', $new);

// --- neexistující kámen se ohlásí, nespadne ---

[, $missing] = runBlockPresenterIn($project, ['action' => 'edit', 'name' => 'neni']);
Assert::contains('neexistuje', $missing);

// --- uložení: platný kámen projde a vznikne soubor ---

$post = [
	'name' => 'novy',
	'description' => 'Popis',
	'command' => 'curl',
	'args' => [
		// Díra v číslování schválně — JS řádky nepřečísluje.
		0 => [0 => '-sS'],
		2 => [0 => '{%url%}'],
	],
	'inputs' => [
		1 => ['name' => 'url', 'required' => '1', 'default' => '', 'description' => 'Adresa'],
	],
	'timeout' => '',
	'allowFailure' => 'none',
	'allowFailureCodes' => '',
	'save' => 'Uložit',
];

[$response] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'do' => 'blockForm-submit'],
	$post,
);

// Úspěch končí přesměrováním na editaci uloženého kamene.
Assert::type(RedirectResponse::class, $response);

$saved = (new BlockParser)->parseFile($project . '/blocks/novy.json');
Assert::same('novy', $saved->name);
Assert::same('curl', $saved->command);

// Díra v indexech se srovnala a pořadí zůstalo.
Assert::same('-sS', $saved->args[0][0]->getSource());
Assert::same('{%url%}', $saved->args[1][0]->getSource());
Assert::same(['url'], array_keys($saved->inputs));

// --- uložení: nedeklarovaná proměnná v args se odmítne ---

$invalid = ['name' => 'vadny', 'args' => [0 => [0 => '{%chybi%}']], 'inputs' => []] + $post;

[$response, $html] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'do' => 'blockForm-submit'],
	$invalid,
);

// Žádné přesměrování — formulář se vrátil s chybou.
Assert::false($response instanceof RedirectResponse);
Assert::contains('chybi', $html);
Assert::false(is_file($project . '/blocks/vadny.json'));

// Hláška patří mezi chyby, ne mezi varování — jinak by uživatel viděl
// konkrétní důvod odmítnutí jako pouhé varování a jako chybu jen tu obecnou
// hlášku. Obojí by prošlo Assert::contains() výše, tohle je pojistka proti
// přehození závažností v šabloně.
Assert::match('~<ul class=error>.*?chybi.*?</ul>~s', $html);
Assert::notMatch('~<ul class=warning>.*?chybi.*?</ul>~s', $html);

// --- zakládání nesmí přepsat existující kámen ---
// writeFile() přepisuje bez ptaní; bez pojistky by tenhle POST tiše
// zahodil obsah echo.json a vrátil přesměrování k nerozeznání od úspěchu.

$echoBefore = FileSystem::read($project . '/blocks/echo.json');

[$response, $html] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'do' => 'blockForm-submit'],
	['name' => 'echo'] + $post,
);

// Žádné přesměrování a soubor beze změny bajt po bajtu.
Assert::false($response instanceof RedirectResponse);
Assert::contains('existuje', $html);
Assert::same($echoBefore, FileSystem::read($project . '/blocks/echo.json'));

// --- posted jméno se při editaci ignoruje — "přejmenování" nesmí založit vidle ---
// Jméno je needitovatelné (setDisabled()), takže i ručně poslaný jiný název
// skončí uložený pod původním jménem kamene, ne jako nový soubor.

[$response] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'echo', 'do' => 'blockForm-submit'],
	[
		'name' => 'prejmenovany',
		'description' => 'Vypíše text',
		'command' => 'echo',
		'args' => [0 => [0 => '{%text%}']],
		'inputs' => [0 => ['name' => 'text', 'required' => '1', 'default' => '', 'description' => '']],
		'timeout' => '5',
		'allowFailure' => 'none',
		'allowFailureCodes' => '',
		'save' => 'Uložit',
	],
);

Assert::type(RedirectResponse::class, $response);
Assert::true(is_file($project . '/blocks/echo.json'));
Assert::false(is_file($project . '/blocks/prejmenovany.json'));

// --- zakládání bez adresáře blocks se ohlásí, nespadne ---
//
// BlockStore::__construct() hází ParseException, když adresář blocks
// neexistuje — na čerstvém projektu je to normální stav. blockFormSucceeded()
// to musí zachytit stejně vlídně jako WriteException, ne nechat výjimku
// propadnout jako neodchycenou.

$bezAdresare = TEMP_DIR . '/edit-bez-blocks';
FileSystem::createDir($bezAdresare);

[$response, $html] = runBlockPresenterIn(
	$bezAdresare,
	['action' => 'edit', 'do' => 'blockForm-submit'],
	[
		'name' => 'novy',
		'description' => '',
		'command' => 'echo',
		'args' => [],
		'inputs' => [],
		'timeout' => '',
		'allowFailure' => 'none',
		'allowFailureCodes' => '',
		'save' => 'Uložit',
	],
);

Assert::false($response instanceof RedirectResponse, 'chybějící adresář nesmí skončit přesměrováním');
Assert::contains('neexistuje', $html);

FileSystem::delete(TEMP_DIR);
