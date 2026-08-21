<?php

declare(strict_types=1);

use Donut\Parser\WorkflowParser;
use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

$project = TEMP_DIR . '/controls';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');

FileSystem::write($project . '/blocks/echo.json', json_encode([
	'name' => 'echo', 'command' => 'echo', 'args' => [],
]));

$write = fn(array $steps) => FileSystem::write(
	$project . '/workflows/w.json',
	json_encode(['name' => 'w', 'steps' => $steps]),
);

$steps = fn(): array => (new WorkflowParser)->parseFile($project . '/workflows/w.json')->steps;

// prázdný if — do jeho větví se dnes nedá nic přidat, protože se
// nevykreslují vůbec
$write([
	['type' => 'set', 'key' => 'a', 'value' => '1'],
	['type' => 'if', 'condition' => ['left' => '{%x%}', 'op' => 'not_empty'], 'then' => []],
	['type' => 'set', 'key' => 'b', 'value' => '2'],
]);

// --- přehled nabízí ovládání ---

[, $html] = runWorkflowPresenterIn($project, ['action' => 'detail', 'name' => 'w']);

Assert::contains('w.json:steps[0]', $html);

// Mazání jde přes POST, ne přes odkaz — GET, který mění soubor, si najde
// přednačítač v prohlížeči. Tvrdíme to na tvaru značkování, ne na tom, že
// v HTML nějaký řetězec chybí: prázdná stránka by takovou aserci splnila taky.
Assert::match('~<form[^>]+method=post[^>]*>\s*<input[^>]+name=at[^>]+value="w\.json:steps\[0]"~', $html);
Assert::notContains('<a href="?do=stepTree-deleteStep', $html);

// U prvního kroku není šipka nahoru, u posledního dolů. Tři kroky → dvakrát
// každá.
Assert::same(2, substr_count($html, 'do=stepTree-moveUp'));
Assert::same(2, substr_count($html, 'do=stepTree-moveDown'));

// Prázdná větev then se vykreslí i tak — jinak by do ní v Tasku 6 nešlo
// přidat „+ krok". Cesta k ní se v HTML nikde neobjeví (prázdný seznam nemá
// žádné ovládání), takže se tvrdí na popisku větve.
Assert::match('~<h3 class="branch-label[^"]*"><span[^>]*>then</span></h3>~', $html);
Assert::match('~<h3 class="branch-label[^"]*"><span[^>]*>else</span></h3>~', $html);

// --- přesun dolů ---

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'detail', 'name' => 'w', 'do' => 'stepTree-moveDown'],
	['at' => 'w.json:steps[0]'],
);

Assert::type(RedirectResponse::class, $response);
Assert::same('if', $steps()[0] instanceof Donut\Format\IfStep ? 'if' : 'jiný');
Assert::same('a', $steps()[1]->key);

// --- přesun nahoru zpátky ---

runWorkflowPresenterIn(
	$project,
	['action' => 'detail', 'name' => 'w', 'do' => 'stepTree-moveUp'],
	['at' => 'w.json:steps[1]'],
);

Assert::same('a', $steps()[0]->key);

// --- mazání ---

runWorkflowPresenterIn(
	$project,
	['action' => 'detail', 'name' => 'w', 'do' => 'stepTree-deleteStep'],
	['at' => 'w.json:steps[0]'],
);

Assert::count(2, $steps());
Assert::type(Donut\Format\IfStep::class, $steps()[0]);

// --- neplatná cesta nespadne na HTTP 500 ---

[, $html] = runWorkflowPresenterIn(
	$project,
	['action' => 'detail', 'name' => 'w', 'do' => 'stepTree-deleteStep'],
	['at' => 'w.json:steps[99]'],
);

Assert::count(2, $steps());

// Neplatná cesta se musí uživateli ohlásit, ne jen tiše nic neudělat — než se
// strom kroků stal komponentou, tuhle hlášku nekontroloval žádný test.
Assert::contains('alert-danger', $html);
Assert::contains('Krok "w.json:steps[99]" neexistuje.', $html);

[, $html] = runWorkflowPresenterIn(
	$project,
	['action' => 'detail', 'name' => 'w', 'do' => 'stepTree-deleteStep'],
	['at' => 'nesmysl'],
);

Assert::contains('alert-danger', $html);
Assert::contains('"nesmysl" není cesta ke kroku.', $html);

// --- cesta z jiného workflow se odmítne, ne aplikuje jako pozice v tomhle ---

[, $html] = runWorkflowPresenterIn(
	$project,
	['action' => 'detail', 'name' => 'w', 'do' => 'stepTree-deleteStep'],
	['at' => 'jine.json:steps[0]'],
);

Assert::count(2, $steps());

Assert::count(2, $steps());

Assert::contains('alert-danger', $html);
Assert::contains('Cesta "jine.json:steps[0]" nepatří workflow "w".', $html);

// --- neplatné workflow se uloží i tak: validace neblokuje ---
//
// Krok run odkazuje na kámen, který neexistuje. Přesun ho nesmí odmítnout.

$write([
	['type' => 'run', 'block' => 'neni'],
	['type' => 'set', 'key' => 'a', 'value' => '1'],
]);

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'detail', 'name' => 'w', 'do' => 'stepTree-moveDown'],
	['at' => 'w.json:steps[0]'],
);

Assert::type(RedirectResponse::class, $response);
Assert::same('a', $steps()[0]->key);

// --- tlačítko × u kroku s podstromem nabízí potvrzení, jinak ne ---
// Je to jediná pojistka před smazáním podstromu — bez ní by × smazal
// vnořené kroky bez varování stejně tiše jako ten jeden krok samotný.

$write([
	['type' => 'set', 'key' => 'a', 'value' => '1'],
	['type' => 'if', 'condition' => ['left' => '{%x%}', 'op' => 'not_empty'], 'then' => [
		['type' => 'set', 'key' => 'b', 'value' => '2'],
	]],
]);

[, $html] = runWorkflowPresenterIn($project, ['action' => 'detail', 'name' => 'w']);

Assert::contains(
	"onclick=\"return confirm(&apos;Smazat i 1 vnořený krok?&apos;)\"",
	$html,
	'krok s podstromem musí nabídnout potvrzení mazání',
);
Assert::same(1, substr_count($html, 'confirm('), 'krok bez dětí (set a, set b) nesmí potvrzení nabízet vůbec');

FileSystem::delete(TEMP_DIR);
