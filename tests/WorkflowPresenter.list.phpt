<?php

declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

$dir = TEMP_DIR . '/projekt';
FileSystem::createDir($dir . '/workflows');
FileSystem::write($dir . '/workflows/sync.json', \json_encode([
	'name' => 'sync',
	'description' => 'Synchronizuje kartu',
	'steps' => [],
]));
FileSystem::write($dir . '/workflows/rozbite.json', 'toto neni json');

[, $html] = runWorkflowPresenterIn($dir, ['action' => 'default']);

// hlavičky sloupců
Assert::contains('<th scope=col>Jméno</th>', $html);
Assert::contains('<th scope=col>Popis</th>', $html);

// tabulka se na úzkém okně posouvá, nemačká
Assert::contains('table-responsive', $html);

// platné workflow: jméno je odkaz na detail, popis je vidět
Assert::match('~<a href="[^"]*action=detail[^"]*">sync</a>~', $html);
Assert::contains('Synchronizuje kartu', $html);

// rozbité workflow: jméno není odkaz na detail, chyba je vidět
Assert::notMatch('~<a href="[^"]*">rozbite</a>~', $html);
Assert::contains('<strong>rozbite</strong>', $html);
Assert::contains('class=error', $html);

// a hlavně: i rozbitý řádek má cestu k opravě a mazání
Assert::match('~<a href="[^"]*name=rozbite[^"]*"[^>]*>upravit</a>~', $html);
Assert::match('~<a href="[^"]*name=sync[^"]*"[^>]*>upravit</a>~', $html);

// odkazy „upravit" se v seznamu odkazů odečítače obrazovky musí rozlišit
Assert::contains('aria-label="Upravit workflow rozbite"', $html);
Assert::contains('aria-label="Upravit workflow sync"', $html);

// tlačítka mají bootstrapí třídy — jinak vypadají jako holé odkazy
// uprostřed jinak nastylované stránky
Assert::match('~<a[^>]*class="btn btn-primary"[^>]*>\\+ nové workflow</a>~', $html);
Assert::match('~<a[^>]*class="btn btn-primary btn-sm"[^>]*>upravit</a>~', $html);

// starý seznam je pryč
Assert::notContains('<ul>', $html);
