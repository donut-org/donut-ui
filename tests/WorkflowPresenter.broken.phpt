<?php

declare(strict_types=1);

use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

// I1: workflow, jehož soubor přestane parsovat, musí jít z GUI opravit
// i smazat — je to ten jediný, který uživatel odstranit potřebuje nejvíc.
// U kamenů se přesně tohle už jednou opravovalo (BlockPresenter.delete.phpt,
// „kámen, který se nedá naparsovat, jde smazat").

$project = TEMP_DIR . '/broken';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');
FileSystem::write($project . '/workflows/rozbity.json', 'toto neni platny json');
FileSystem::write($project . '/workflows/w.json', json_encode(['name' => 'w', 'steps' => []]));

// --- seznam nabídne odkaz na úpravu i u vadného workflow ---

[, $seznam] = runWorkflowPresenterIn($project, ['action' => 'default']);

Assert::contains('class=error', $seznam, 'vadné workflow se ohlásí');
Assert::contains('name=rozbity&amp;action=edit', $seznam, 'seznam musí u vadného workflow nabídnout odkaz na úpravu');

// --- edit vykreslí mazací formulář, i když se soubor nenaparsoval ---

[, $html] = runWorkflowPresenterIn($project, ['action' => 'edit', 'name' => 'rozbity']);

Assert::contains('class=error', $html, 'chyba parsování se ohlásí');
Assert::contains('Smazat', $html, 'mazání dřív sedělo uvnitř {if !$error}');

// --- drobečky rozbitého workflow nesou jméno souboru, ne slovo „chyba" ---
// Zrovna na stránce, kde se soubor nenaparsoval, je jméno z adresy to jediné,
// podle čeho uživatel pozná, co se nepovedlo otevřít.

[, $detailRozbity] = runWorkflowPresenterIn($project, ['action' => 'detail', 'name' => 'rozbity']);

Assert::contains('class=error', $detailRozbity, 'chyba parsování se ohlásí');
Assert::match('~<li class="breadcrumb-item active" aria-current=page>rozbity</li>~', $detailRozbity);
Assert::notContains('>chyba</li>', $detailRozbity);

// --- a nabídnou úpravu hlavičky, stejně jako detail rozbitého kamene ---
// Odkaz dřív seděl uvnitř {if $workflow !== null}, takže detail rozbitého
// workflow ukázal jen hlášku a cesta k opravě z něj nevedla nikam.
Assert::match(
	'~<a href="[^"]*name=rozbity[^"]*">upravit hlavičku</a>~',
	$detailRozbity,
	'i rozbité workflow musí nabídnout cestu k opravě',
);

// --- POST soubor skutečně smaže ---
// Mazání nesmí záviset na tom, že se soubor podařilo naparsovat: jméno jde
// ze skrytého pole, ne z načteného workflow.

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'rozbity', 'do' => 'deleteWorkflowForm-submit'],
	['name' => 'rozbity', 'save' => 'Smazat'],
);

Assert::type(RedirectResponse::class, $response);
Assert::false(\is_file($project . '/workflows/rozbity.json'));

// --- M6: POST s prázdným jménem skončí hláškou, ne pádem ---

[$response, $html] = runWorkflowPresenterIn(
	$project,
	['action' => 'edit', 'name' => 'w', 'do' => 'deleteWorkflowForm-submit'],
	['name' => '', 'save' => 'Smazat'],
);

Assert::false($response instanceof RedirectResponse, 'není co mazat, tedy ani kam přesměrovávat');
Assert::contains('Není co mazat', $html);
Assert::true(\is_file($project . '/workflows/w.json'), 'prázdné jméno nesmí sáhnout na nic jiného');


// I4: projekt, který má workflows/, ale ne blocks/, musí detail pořád
// vykreslit — bez validace, ale se stromem kroků a odkazem na hlavičku.
// blockNames() chybějící adresář kamenů vědomě toleruje, renderDetail() na
// něm dřív skončil bez <h1>.

$bezKamenu = TEMP_DIR . '/bez-kamenu';
FileSystem::createDir($bezKamenu . '/workflows');
FileSystem::write($bezKamenu . '/workflows/w.json', json_encode([
	'name' => 'w',
	'steps' => [['type' => 'set', 'key' => 'a', 'value' => '1']],
]));

[, $detail] = runWorkflowPresenterIn($bezKamenu, ['action' => 'detail', 'name' => 'w']);

Assert::contains('blocks', $detail, 'chybějící kameny se ohlásí');
Assert::contains('<h1>w</h1>', $detail, 'hlavička se vykreslí i bez kamenů');
Assert::contains('upravit hlavičku', $detail, 'odkaz na obálku nesmí zmizet');
Assert::contains('<strong>set</strong>', $detail, 'strom kroků se vykreslí i bez validace');

FileSystem::delete(TEMP_DIR);
