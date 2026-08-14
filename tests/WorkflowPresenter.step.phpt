<?php

declare(strict_types=1);

use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\RunStep;
use Donut\Format\SetStep;
use Donut\Parser\WorkflowParser;
use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/workflowPresenter.php';

$project = TEMP_DIR . '/step';
FileSystem::createDir($project . '/blocks');
FileSystem::createDir($project . '/workflows');

FileSystem::write($project . '/blocks/jq.json', json_encode([
	'name' => 'jq', 'command' => 'jq', 'args' => [],
	'inputs' => ['filter' => ['required' => true]],
	'stdin' => ['required' => true],
]));

FileSystem::write($project . '/workflows/w.json', json_encode([
	'name' => 'w',
	'steps' => [
		['type' => 'run', 'block' => 'jq', 'in' => ['filter' => '.id'], 'out' => ['result' => 'id']],
		['type' => 'if', 'condition' => ['left' => '{%id%}', 'op' => 'not_empty'], 'then' => []],
	],
]));

$steps = fn(): array => (new WorkflowParser)->parseFile($project . '/workflows/w.json')->steps;

// --- úprava existujícího kroku: formulář je předvyplněný ---

[, $html] = runWorkflowPresenterIn($project, [
	'action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]',
]);

Assert::contains('value="jq"', $html);
Assert::contains('.id', $html);
Assert::contains('<form', $html);

// --- uložení úpravy ---

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]', 'do' => 'stepForm-submit'],
	[
		'type' => 'run', 'name' => 'pojmenovaný', 'block' => 'jq',
		// díra v číslování schválně
		'in' => [0 => ['key' => 'filter', 'value' => '.title'], 2 => ['key' => 'stdin', 'value' => '{%x%}']],
		'out' => [0 => ['channel' => 'result', 'value' => 'title']],
		'timeout' => '', 'allowFailure' => 'inherit', 'allowFailureCodes' => '',
		'save' => 'Uložit',
	],
);

Assert::type(RedirectResponse::class, $response);

$run = $steps()[0];
Assert::type(RunStep::class, $run);
Assert::same('pojmenovaný', $run->name);
Assert::same(['filter', 'stdin'], array_keys($run->in));
Assert::same('.title', $run->in['filter']->getSource());
Assert::same(['result' => 'title'], $run->out);

// Zbytek workflow zůstal — úprava kroku nesmí sáhnout na sousedy.
Assert::count(2, $steps());
Assert::type(IfStep::class, $steps()[1]);

// --- nový krok se vloží na zadanou pozici ---

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[1]', 'type' => 'set', 'do' => 'stepForm-submit'],
	['type' => 'set', 'name' => '', 'key' => 'branch', 'value' => 'f/{%id%}', 'save' => 'Uložit'],
);

Assert::count(3, $steps());
Assert::type(SetStep::class, $steps()[1]);
Assert::same('branch', $steps()[1]->key);
Assert::type(IfStep::class, $steps()[2]);

// --- nový krok do prázdné větve ---

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[2].then[0]', 'type' => 'foreach', 'do' => 'stepForm-submit'],
	['type' => 'foreach', 'name' => '', 'over' => '{%list%}', 'as' => 'row', 'save' => 'Uložit'],
);

$if = $steps()[2];
Assert::type(IfStep::class, $if);
Assert::count(1, $if->then);
Assert::type(ForeachStep::class, $if->then[0]);
Assert::same('row', $if->then[0]->as);

// --- úprava if nesmí zahodit jeho větve ---

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[2]', 'do' => 'stepForm-submit'],
	['type' => 'if', 'name' => 'přejmenovaná', 'left' => '{%id%}', 'op' => 'empty', 'right' => '', 'save' => 'Uložit'],
);

$if = $steps()[2];
Assert::same('přejmenovaná', $if->name);
Assert::same('empty', $if->condition->op);
Assert::count(1, $if->then, 'větev then se úpravou podmínky nesmí ztratit');

// --- dej foreach vlastní podstrom, aby bylo co chránit ---

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[2].then[0].steps[0]', 'type' => 'set', 'do' => 'stepForm-submit'],
	['type' => 'set', 'name' => '', 'key' => 'inner', 'value' => 'x', 'save' => 'Uložit'],
);

$foreach = $steps()[2]->then[0];
Assert::type(ForeachStep::class, $foreach);
Assert::count(1, $foreach->steps);

// --- úprava foreach nesmí zahodit jeho podstrom ---

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[2].then[0]', 'do' => 'stepForm-submit'],
	['type' => 'foreach', 'name' => 'přejmenovaný', 'over' => '{%items%}', 'as' => 'item', 'save' => 'Uložit'],
);

$foreach = $steps()[2]->then[0];
Assert::same('přejmenovaný', $foreach->name);
Assert::same('item', $foreach->as);
Assert::count(1, $foreach->steps, 'podstrom foreach se úpravou over/as nesmí ztratit');

// --- server rozhoduje o typu kroku, ne skrytý input z POSTu ---
//
// Skrytý <input name=type> je obyčejné pole formuláře — POST ho může
// poslat jinak, než jak byl formulář sestavený. Kdyby se mu věřilo,
// StepMapper::toStep() by z hodnot foreach formuláře (bez pole "key")
// postavil SetStep s prázdným klíčem a celý podstrom foreach by zmizel.
// Server proto typ z POSTu ignoruje a použije ten, podle kterého formulář
// sestavil ($this->stepType, odvozený z editovaného kroku).

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[2].then[0]', 'do' => 'stepForm-submit'],
	['type' => 'set', 'name' => '', 'over' => '{%items%}', 'as' => 'item', 'save' => 'Uložit'],
);

$foreach = $steps()[2]->then[0];
Assert::type(ForeachStep::class, $foreach, 'zfalšovaný type v POSTu nesmí změnit typ kroku');
Assert::count(1, $foreach->steps, 'zfalšovaný type nesmí smazat podstrom');

// --- neplatná cesta se ohlásí, nespadne ---

[, $html] = runWorkflowPresenterIn($project, [
	'action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[99]',
]);

Assert::contains('neexistuje', $html);

// --- neplatné workflow se uloží i tak: validace neblokuje ---
//
// Kámen jq vyžaduje vstup filter i stdin; krok, který nevyplní ani jeden,
// je pro validátor chyba. Uložit se přesto musí.
//
// Pozn.: neplatnost se schválně nevyrábí neexistujícím jménem kamene —
// pole `block` je addSelect nad seznamem kamenů a Nette hodnotu mimo seznam
// odmítne dřív, než se k uložení vůbec dojde. Testovalo by se tím chování
// formuláře, ne to, že validace workflow neblokuje.

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]', 'do' => 'stepForm-submit'],
	[
		'type' => 'run', 'name' => '', 'block' => 'jq',
		'in' => [], 'out' => [], 'timeout' => '',
		'allowFailure' => 'inherit', 'allowFailureCodes' => '', 'save' => 'Uložit',
	],
);

Assert::same([], $steps()[0]->in, 'krok bez povinných vstupů se uloží i tak');

FileSystem::delete(TEMP_DIR);
