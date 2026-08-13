<?php

declare(strict_types=1);

use Donut\Parser\BlockParser;
use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/blockPresenter.php';

$projekt = TEMP_DIR . '/edit';
FileSystem::createDir($projekt . '/blocks');
FileSystem::createDir($projekt . '/workflows');

FileSystem::write($projekt . '/blocks/echo.json', json_encode([
	'name' => 'echo',
	'description' => 'Vypíše text',
	'command' => 'echo',
	'args' => [['{%text%}']],
	'inputs' => ['text' => ['required' => true]],
	'timeout' => 5,
]));

// --- editace existujícího: formulář je předvyplněný ---

[, $html] = runBlockPresenterIn($projekt, ['action' => 'edit', 'name' => 'echo']);

Assert::contains('value="echo"', $html);
Assert::contains('Vypíše text', $html);
Assert::contains('{%text%}', $html);
Assert::contains('value="5"', $html);

// --- zakládání nového: prázdný formulář, žádný pád ---

[, $novy] = runBlockPresenterIn($projekt, ['action' => 'edit']);

Assert::contains('<form', $novy);
Assert::notContains('Vypíše text', $novy);

// --- neexistující kámen se ohlásí, nespadne ---

[, $chybi] = runBlockPresenterIn($projekt, ['action' => 'edit', 'name' => 'neni']);
Assert::contains('neexistuje', $chybi);

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
	$projekt,
	['action' => 'edit', 'do' => 'blockForm-submit'],
	$post,
);

// Úspěch končí přesměrováním na editaci uloženého kamene.
Assert::type(RedirectResponse::class, $response);

$ulozeny = (new BlockParser)->parseFile($projekt . '/blocks/novy.json');
Assert::same('novy', $ulozeny->name);
Assert::same('curl', $ulozeny->command);

// Díra v indexech se srovnala a pořadí zůstalo.
Assert::same('-sS', $ulozeny->args[0][0]->getSource());
Assert::same('{%url%}', $ulozeny->args[1][0]->getSource());
Assert::same(['url'], array_keys($ulozeny->inputs));

// --- uložení: nedeklarovaná proměnná v args se odmítne ---

$vadny = ['name' => 'vadny', 'args' => [0 => [0 => '{%chybi%}']], 'inputs' => []] + $post;

[$response, $html] = runBlockPresenterIn(
	$projekt,
	['action' => 'edit', 'do' => 'blockForm-submit'],
	$vadny,
);

// Žádné přesměrování — formulář se vrátil s chybou.
Assert::false($response instanceof RedirectResponse);
Assert::contains('chybi', $html);
Assert::false(is_file($projekt . '/blocks/vadny.json'));

FileSystem::delete(TEMP_DIR);
