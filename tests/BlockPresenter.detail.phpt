<?php

declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/blockPresenter.php';

$dir = TEMP_DIR . '/projekt';
FileSystem::createDir($dir . '/blocks');
FileSystem::createDir($dir . '/workflows');

FileSystem::write($dir . '/blocks/curl-get.json', \json_encode([
	'name' => 'curl-get',
	'description' => 'Stáhne adresu',
	'command' => 'curl',
	'args' => [['-sS'], ['-H', '{%hlavicka%}'], ['{%url%}']],
	'inputs' => [
		'url' => ['required' => true, 'description' => 'Úplná adresa'],
		'hlavicka' => ['required' => false, 'default' => 'Accept: */*'],
	],
	'stdin' => ['required' => false, 'description' => 'Tělo požadavku'],
	'timeout' => 30,
	'allow_failure' => [0, 22],
]));

FileSystem::write($dir . '/workflows/sync.json', \json_encode([
	'name' => 'sync',
	'steps' => [['type' => 'run', 'block' => 'curl-get', 'in' => ['url' => 'x']]],
]));

[, $html] = runBlockPresenterIn($dir, ['action' => 'detail', 'name' => 'curl-get']);

// příkaz a popis
// Samotné „curl" by tuhle aserci neuhlídalo — kámen se jmenuje curl-get,
// takže to slovo je v HTML z <h1>, z drobečků i z odkazů, a příkaz mohl
// z detailu úplně zmizet, aniž by to sada poznala.
Assert::contains('příkaz: <code>curl</code>', $html);
Assert::contains('Stáhne adresu', $html);

// argumenty — dnešní přehled je nevypisuje vůbec, detail je vypsat musí
Assert::contains('-sS', $html);
Assert::contains('-H', $html);

// vstupy i s povinností a výchozí hodnotou
Assert::contains('url', $html);
Assert::contains('povinný', $html);
Assert::contains('Úplná adresa', $html);
Assert::contains('Accept: */*', $html);

// stdin, timeout, allow_failure
Assert::contains('Tělo požadavku', $html);
Assert::contains('30', $html);
Assert::contains('22', $html);

// kdo kámen používá
Assert::contains('sync', $html);

// cesta na editaci
Assert::match('~<a href="[^"]*action=edit[^"]*"[^>]*>upravit</a>~', $html);


// --- rozbitý kámen musí jít otevřít ---
FileSystem::write($dir . '/blocks/rozbity.json', 'toto neni json');
FileSystem::write($dir . '/workflows/oprav.json', \json_encode([
	'name' => 'oprav',
	'steps' => [['type' => 'run', 'block' => 'rozbity', 'in' => []]],
]));

[, $rozbity] = runBlockPresenterIn($dir, ['action' => 'detail', 'name' => 'rozbity']);

Assert::contains('class=error', $rozbity);
Assert::match('~<a href="[^"]*action=edit[^"]*"[^>]*>upravit</a>~', $rozbity);

// a hlavně: i u rozbitého kamene je vidět, kdo ho používá — $usedBy se počítá
// z workflow, ne z kamene, a právě před opravou nebo mazáním je to ta
// nejdůležitější informace na stránce
Assert::contains('<h2>Používá</h2>', $rozbity);
Assert::contains('<li>oprav</li>', $rozbity);
