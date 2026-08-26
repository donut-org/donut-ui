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
Assert::contains("Block 'neni' does not exist.", $missing);

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
//
// .*? mezi <div> a </div> by přeskočil přes celý zbytek stránky až k první
// další </div> za slovem „chybi" — a to je i div formuláře s obecnou
// hláškou „Kámen se neuložil"; „chybi" se totiž znovu objeví o kus níž
// v hodnotě políčka args. Vzor je proto svázaný na strukturu ze Step 2
// (div > ul.mb-0 > li) bez skoku přes jiný tag.
Assert::match('~<div class="alert alert-danger">\s*<ul class="mb-0">\s*<li>[^<]*chybi[^<]*</li>~s', $html);
Assert::notMatch('~<div class="alert alert-warning">\s*<ul class="mb-0">\s*<li>[^<]*chybi[^<]*</li>~s', $html);

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
Assert::contains('does not exist', $html);
// M8: hláška musí říct, co s tím — jinak je prázdný projekt slepá ulička.
Assert::contains('mkdir -p ' . $bezAdresare . '/blocks', $html);

// --- cizí POST nesmí formulář kamene vyprázdnit ---
//
// I2: formShape() se ptá, jestli POST patří blockFormu, ale setDefaults()
// se dřív ptal jen „je to POST?". Po neúspěšném mazání měl formulář správný
// tvar a všechny hodnoty prázdné — a „Uložit" ho takhle zapsalo.

[$response, $html] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'echo', 'do' => 'deleteForm-submit'],
	['name' => 'neni', 'delete' => 'Smazat'],
);

Assert::false($response instanceof RedirectResponse, 'mazání selhalo, stránka se překreslila');
Assert::contains('value="echo"', $html, 'jméno drží setDefaultValue()');
Assert::contains('Vypíše text', $html, 'popis se nesmí ztratit');
Assert::contains('{%text%}', $html, 'argumenty se nesmí ztratit');
Assert::contains('value="5"', $html, 'timeout se nesmí ztratit');

// --- N1: GET s `do=blockForm-submit` v adrese je pořád GET ---
// Ručně složená adresa nese signál, ale žádná data — getPost() vrátí [],
// což není prázdný POST formuláře. Bez testu na HTTP metodu se setDefaults()
// přeskočí, formulář se vykreslí prázdný a „Uložit" tak kámen zapíše.

[, $html] = runBlockPresenterIn($project, ['action' => 'edit', 'name' => 'echo', 'do' => 'blockForm-submit']);

Assert::contains('value="echo"', $html, 'jméno drží setDefaultValue()');
Assert::contains('Vypíše text', $html, 'popis se nesmí ztratit — GET nic neodeslal');
// Jméno i příkaz jsou tady shodou okolností „echo" — na příkaz se proto musí
// ptát adresně, jinak by asercí prošlo předvyplněné jméno.
Assert::match('~name="command"[^>]*value="echo"~', $html, 'příkaz se nesmí ztratit');
Assert::contains('{%text%}', $html, 'argumenty se nesmí ztratit');

// --- POST z cizího webu se nesmí dostat k zápisu ---
// Táž kontrola jako na druhé polovině GUI (WorkflowPresenter.headerForm.phpt):
// GUI nemá CSRF token ani session, kryje to jen Fetch Metadata ve
// Form::signalReceived(). Bez hlavičky sec-fetch-site skončí signál
// v detectedCsrf() → redirect('this'), takže o výsledku rozhoduje disk,
// ne typ odpovědi — přesměrování by přišlo i po úspěšném uložení.

$before = FileSystem::read($project . '/blocks/echo.json');

[$response] = runBlockPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'echo', 'do' => 'blockForm-submit'],
	[
		'name' => 'echo',
		'description' => 'Z cizího webu',
		'command' => 'echo',
		'args' => [],
		'inputs' => [],
		'timeout' => '',
		'allowFailure' => 'none',
		'allowFailureCodes' => '',
		'save' => 'Uložit',
	],
	sameOrigin: false,
);

Assert::type(RedirectResponse::class, $response);
Assert::same($before, FileSystem::read($project . '/blocks/echo.json'), 'cizí původ nesmí nic zapsat');

FileSystem::delete(TEMP_DIR);
