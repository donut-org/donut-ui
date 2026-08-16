<?php

declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

$dir = TEMP_DIR . '/projekt';
FileSystem::createDir($dir . '/workflows');
FileSystem::write($dir . '/workflows/w.json', \json_encode([
	'name' => 'w',
	'inputs' => ['repo' => ['required' => true, 'description' => 'Repozitář']],
	'steps' => [],
]));

[, $html] = runWorkflowPresenterIn($dir, ['action' => 'edit', 'name' => 'w']);

// hlavička je to, co uživateli říká, co do kterého políčka patří
Assert::contains('<th scope=col>Jméno</th>', $html);
Assert::contains('<th scope=col>Povinný</th>', $html);
Assert::contains('<th scope=col>Výchozí</th>', $html);
Assert::contains('<th scope=col>Popis</th>', $html);

// značkování, na které sahá rows.js
Assert::contains('<tbody id=inputs>', $html);
Assert::match('~<tr class=js-row>~', $html);
Assert::contains('js-del-row', $html);
Assert::contains('data-add=inputs', $html);

// stará třída .row je pryč — kolidovala by s Bootstrap gridem
Assert::notMatch('~<div class=row[ >]~', $html);

// hodnoty ze souboru v tabulce zůstávají
Assert::match('~name="inputs\[0\]\[name\]"[^>]*value="repo"~', $html);
Assert::match('~name="inputs\[0\]\[description\]"[^>]*value="Repozitář"~', $html);

// nápověda pod tabulkou
Assert::contains('--jmeno=hodnota', $html);
Assert::contains('když ji volající nepředá', $html);


// --- stránka kroku: tabulky in a out -----------------------------------

$krok = TEMP_DIR . '/krok';
FileSystem::createDir($krok . '/blocks');
FileSystem::createDir($krok . '/workflows');
FileSystem::write($krok . '/blocks/jq.json', \json_encode([
	'name' => 'jq',
	'command' => 'jq',
	'inputs' => ['filter' => ['required' => true]],
	'stdin' => ['required' => true],
]));
FileSystem::write($krok . '/workflows/w.json', \json_encode([
	'name' => 'w',
	'steps' => [[
		'type' => 'run',
		'block' => 'jq',
		'in' => ['filter' => '.id'],
		'out' => ['result' => 'id'],
	]],
]));

[, $stepHtml] = runWorkflowPresenterIn($krok, [
	'action' => 'step',
	'name' => 'w',
	'at' => 'w.json:steps[0]',
]);

Assert::contains('<th scope=col>Vstup kamene</th>', $stepHtml);
Assert::contains('<th scope=col>Co z kamene</th>', $stepHtml);
Assert::contains('<th scope=col>Pod jakým klíčem do mapy</th>', $stepHtml);

// literál {%klíč%} v hlavičce — Latte ho umí vypsat jen přes {='…'}
Assert::contains('&#123;%klíč%}', $stepHtml);

Assert::contains('<tbody id=in>', $stepHtml);
Assert::contains('<tbody id=out>', $stepHtml);
Assert::contains('data-add=in', $stepHtml);
Assert::contains('data-add=out', $stepHtml);
Assert::notMatch('~<div class=row[ >]~', $stepHtml);

// hodnoty kroku v tabulkách zůstávají
Assert::match('~name="in\[0\]\[key\]"[^>]*value="filter"~', $stepHtml);
Assert::match('~name="out\[0\]\[value\]"[^>]*value="id"~', $stepHtml);
