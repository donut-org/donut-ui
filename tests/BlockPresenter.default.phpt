<?php

declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/blockPresenter.php';

// --- přehled kamenů se vykreslí i bez adresáře workflows ---
// loadWorkflows() chrání stránku před ParseException, když adresář chybí —
// čerstvý projekt nemusí mít workflows/ vůbec.

$noWorkflows = TEMP_DIR . '/no-workflows';
FileSystem::createDir($noWorkflows . '/blocks');

FileSystem::write($noWorkflows . '/blocks/echo.json', json_encode([
	'name' => 'echo', 'command' => 'echo', 'args' => [],
]));

[, $html] = runBlockPresenterIn($noWorkflows, ['action' => 'default']);

Assert::contains('echo', $html);
// Kámen, který nikdo nepoužívá, má buňku „Používá" prázdnou. Ptát se na
// nepřítomnost slova „používá" už nejde — je z něj hlavička sloupce.
Assert::contains('<th scope=col>Používá</th>', $html);
Assert::match('~<td>\s*</td>~', $html);

// --- přehled kamenů se vykreslí i s rozbitým workflow souborem ---
// Vadné workflow nesmí shodit stránku — loadWorkflows() ho jen přeskočí,
// stejně jako vadný kámen v renderDefault().

$brokenWorkflow = TEMP_DIR . '/broken-workflow';
FileSystem::createDir($brokenWorkflow . '/blocks');
FileSystem::createDir($brokenWorkflow . '/workflows');

FileSystem::write($brokenWorkflow . '/blocks/echo.json', json_encode([
	'name' => 'echo', 'command' => 'echo', 'args' => [],
]));
FileSystem::write($brokenWorkflow . '/workflows/rozbite.json', 'toto neni json');

[, $html] = runBlockPresenterIn($brokenWorkflow, ['action' => 'default']);

Assert::contains('echo', $html);

// --- chybějící adresář kamenů říká, co s tím ---
// M8: prázdný projekt byl slepá ulička — hláška oznámila, že adresář není,
// ale ne že stačí jeden mkdir. Adresáře GUI vědomě nezakládá.

$prazdny = TEMP_DIR . '/prazdny';
FileSystem::createDir($prazdny);

[, $html] = runBlockPresenterIn($prazdny, ['action' => 'default']);

Assert::contains('neexistuje', $html);
Assert::contains('mkdir blocks', $html);

// --- rozbitý kámen v přehledu: chyba je vidět a cesta k opravě zůstává ---
// Nenaparsovatelný soubor je ten, u kterého uživatel cestu k opravě a mazání
// potřebuje nejvíc. U workflow to musel doplňovat až předchozí projekt jako
// Important nález; u kamenů to do teď nehlídala žádná aserce.

$sRozbitym = TEMP_DIR . '/s-rozbitym';
FileSystem::createDir($sRozbitym . '/blocks');
FileSystem::write($sRozbitym . '/blocks/dobry.json', json_encode([
	'name' => 'dobry', 'command' => 'echo', 'args' => [],
]));
FileSystem::write($sRozbitym . '/blocks/rozbity.json', 'toto neni json');

[, $html] = runBlockPresenterIn($sRozbitym, ['action' => 'default']);

Assert::contains('<strong>rozbity</strong>', $html);
Assert::contains('class=error', $html);
Assert::match('~<a href="[^"]*name=rozbity[^"]*">upravit</a>~', $html);

// dobrý kámen vedle něj zůstane odkazem na detail
Assert::match('~<a href="[^"]*action=detail[^"]*">dobry</a>~', $html);

FileSystem::delete(TEMP_DIR);
