<?php

declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';
require __DIR__ . '/inc/blockPresenter.php';

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

// mazací tlačítko má přístupné jméno taky — bez aria-label by čtečka slyšela
// jen "tlačítko ×"
Assert::contains('js-del-row" aria-label="Smazat řádek"', $html);

// stará třída .row je pryč — kolidovala by s Bootstrap gridem
Assert::notMatch('~<div class=row[ >]~', $html);

// hlavička pojmenovává buňku, ne políčko v ní — odečítač obrazovky potřebuje
// aria-label. Hlavičky přitom zůstávají (aserce na ně jsou výš).
Assert::match('~name="inputs\[0\]\[name\]"[^>]*aria-label="Jméno"~', $html);
Assert::match('~name="inputs\[0\]\[required\]"[^>]*aria-label="Povinný"~', $html);
Assert::match('~name="inputs\[0\]\[default\]"[^>]*aria-label="Výchozí"~', $html);
Assert::match('~name="inputs\[0\]\[description\]"[^>]*aria-label="Popis"~', $html);

// v úzkém okně se tabulka posouvá, sloupce se nemačkají
Assert::contains('<div class=table-responsive>', $html);

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

// mazací tlačítko má přístupné jméno taky — bez aria-label by čtečka slyšela
// jen "tlačítko ×"; obě tabulky mají po dvou řádcích (vyplněný + prázdný
// navíc), tedy dohromady čtyři mazací tlačítka
Assert::same(4, \substr_count($stepHtml, 'js-del-row" aria-label="Smazat řádek"'));

// políčka obou tabulek mají přístupné jméno a obě tabulky se v úzkém okně
// posouvají
Assert::match('~name="in\[0\]\[key\]"[^>]*aria-label="Vstup kamene"~', $stepHtml);
Assert::match('~name="in\[0\]\[value\]"[^>]*aria-label="Hodnota"~', $stepHtml);
Assert::match('~name="out\[0\]\[channel\]"[^>]*aria-label="Co z kamene"~', $stepHtml);
Assert::match('~name="out\[0\]\[value\]"[^>]*aria-label="Pod jakým klíčem do mapy"~', $stepHtml);
Assert::same(2, \substr_count($stepHtml, '<div class=table-responsive>'));

// hodnoty kroku v tabulkách zůstávají
Assert::match('~name="in\[0\]\[key\]"[^>]*value="filter"~', $stepHtml);
Assert::match('~name="out\[0\]\[value\]"[^>]*value="id"~', $stepHtml);


// --- stránka kamene: tabulka vstupů ------------------------------------
// Task 6 měnil tři šablony, ne dvě. Bez tohohle bloku projde sadou i úplné
// rozbití značkování v Block/edit.latte.

$kamen = TEMP_DIR . '/kamen';
FileSystem::createDir($kamen . '/blocks');
FileSystem::write($kamen . '/blocks/k.json', \json_encode([
	'name' => 'k',
	'command' => 'echo',
	'args' => [['-n']],
	'inputs' => ['text' => ['required' => true, 'description' => 'Co vypsat']],
]));

[, $blockHtml] = runBlockPresenterIn($kamen, ['action' => 'edit', 'name' => 'k']);

// hlavička je to, co uživateli říká, co do kterého políčka patří
Assert::contains('<th scope=col>Jméno</th>', $blockHtml);
Assert::contains('<th scope=col>Povinný</th>', $blockHtml);
Assert::contains('<th scope=col>Výchozí</th>', $blockHtml);
Assert::contains('<th scope=col>Popis</th>', $blockHtml);

// značkování, na které sahá rows.js
Assert::contains('<tbody id=inputs>', $blockHtml);
Assert::match('~<tr class=js-row>~', $blockHtml);
Assert::contains('js-del-row', $blockHtml);
Assert::contains('data-add=inputs', $blockHtml);

// mazací tlačítko má přístupné jméno taky — bez aria-label by čtečka slyšela
// jen "tlačítko ×"
Assert::contains('js-del-row" aria-label="Smazat řádek"', $blockHtml);

// stará třída .row je pryč — kolidovala by s Bootstrap gridem
Assert::notMatch('~<div class=row[ >]~', $blockHtml);

// políčka mají přístupné jméno a tabulka se v úzkém okně posouvá
Assert::match('~name="inputs\[0\]\[name\]"[^>]*aria-label="Jméno"~', $blockHtml);
Assert::match('~name="inputs\[0\]\[required\]"[^>]*aria-label="Povinný"~', $blockHtml);
Assert::match('~name="inputs\[0\]\[default\]"[^>]*aria-label="Výchozí"~', $blockHtml);
Assert::match('~name="inputs\[0\]\[description\]"[^>]*aria-label="Popis"~', $blockHtml);
Assert::contains('<div class=table-responsive>', $blockHtml);

// hodnoty ze souboru v tabulce zůstávají
Assert::match('~name="inputs\[0\]\[name\]"[^>]*value="text"~', $blockHtml);
Assert::match('~name="inputs\[0\]\[description\]"[^>]*value="Co vypsat"~', $blockHtml);

// nápověda pod tabulkou
Assert::contains('--jmeno=hodnota', $blockHtml);
Assert::contains('když ji volající nepředá', $blockHtml);

// argumenty jedné skupiny stojí vedle sebe a skupina je vidět jako celek;
// bez w-auto by z nich form-control udělal svislý sloupec přes celou šířku
Assert::match('~<div class="arg-group[^"]*\bd-flex\b[^"]*"~', $blockHtml);
Assert::match('~<div class="arg-group[^"]*\bborder\b[^"]*"~', $blockHtml);
Assert::match('~name="args\[0\]\[0\]"[^>]*class="form-control w-auto"~', $blockHtml);


// --- formuláře jdou z FormFactory --------------------------------------
// FormFactory.phpt testuje továrnu izolovaně a o skutečných stránkách netvrdí
// nic. Bez těchhle aserci projde sadou návrat všech pěti formulářů na new Form.

// WorkflowPresenter: hlavička (text, checkbox, submit)
Assert::contains('class="form-control"', $html);
Assert::contains('class="form-check-input"', $html);
Assert::contains('class="btn btn-primary"', $html);

// WorkflowPresenter: krok (navíc rozbalovací seznam)
Assert::contains('class="form-control"', $stepHtml);
Assert::contains('class="form-select"', $stepHtml);
Assert::contains('class="btn btn-primary"', $stepHtml);

// BlockPresenter: kámen
Assert::contains('class="form-control"', $blockHtml);
Assert::contains('class="form-check-input"', $blockHtml);
Assert::contains('class="btn btn-primary"', $blockHtml);

// přepínače („Povolené selhání") jsou na obou stránkách a třídu mít musí —
// bez ní vypadají uprostřed bootstrapí stránky jako nedodělek
Assert::notMatch('~<input type="radio"(?![^>]*form-check-input)~', $blockHtml);
Assert::notMatch('~<input type="radio"(?![^>]*form-check-input)~', $stepHtml);
Assert::contains('<input type="radio"', $blockHtml);
Assert::contains('<input type="radio"', $stepHtml);

// vlastní třída se z prototypu nepřepíše — mazací tlačítko si o btn-danger
// řeklo při vzniku a továrna mu ji nechává
Assert::contains('btn btn-danger', $html);
Assert::contains('btn btn-danger', $blockHtml);
