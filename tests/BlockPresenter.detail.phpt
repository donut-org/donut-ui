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
Assert::contains('curl', $html);
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
Assert::match('~<a href="[^"]*action=edit[^"]*">upravit</a>~', $html);


// --- rozbitý kámen musí jít otevřít ---
FileSystem::write($dir . '/blocks/rozbity.json', 'toto neni json');

[, $rozbity] = runBlockPresenterIn($dir, ['action' => 'detail', 'name' => 'rozbity']);

Assert::contains('class=error', $rozbity);
Assert::match('~<a href="[^"]*action=edit[^"]*">upravit</a>~', $rozbity);
