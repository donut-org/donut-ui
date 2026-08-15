<?php

declare(strict_types=1);

use Donut\Format\Input;
use Donut\Format\SetStep;
use Donut\Format\Workflow;
use Donut\Gui\WorkflowMapper;
use Donut\Parser\WorkflowParser;
use Donut\Template;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

// --- round-trip nad referenční zátěží, na hlavičce a vstupech ---
//
// Kroky formulář needituje, takže se do porovnání neberou — toWorkflow()
// je dostane z původního workflow.

$files = \glob(__DIR__ . '/../../docs/workflows/donut/workflows/*.json');
Assert::count(4, $files === false ? [] : $files);

foreach ($files === false ? [] : $files as $file) {
	$original = (new WorkflowParser)->parseFile($file);
	$again = WorkflowMapper::toWorkflow(WorkflowMapper::toValues($original), $original);

	Assert::same($original->name, $again->name, \basename($file));
	Assert::same($original->description, $again->description, \basename($file));
	Assert::same(\serialize($original->inputs), \serialize($again->inputs), \basename($file));
}

// --- kroky se převezmou z původního workflow, ne z hodnot ---
//
// Tohle je ta past: bez $original by úprava hlavičky smazala celý strom.

$sKroky = new Workflow(
	name: 'w',
	steps: [new SetStep(key: 'a', value: Template::parse('1'))],
	description: 'Původní popis',
);

$poUprave = WorkflowMapper::toWorkflow(
	['name' => 'w', 'description' => 'Nový popis', 'inputs' => []],
	$sKroky,
);

Assert::same('Nový popis', $poUprave->description);
Assert::count(1, $poUprave->steps, 'kroky se úpravou hlavičky nesmí ztratit');
Assert::same('a', $poUprave->steps[0]->key);

// --- bez původního workflow (zakládání) vzniká prázdné ---

$nove = WorkflowMapper::toWorkflow(['name' => 'nove', 'description' => '', 'inputs' => []]);

Assert::same('nove', $nove->name);
Assert::null($nove->description);
Assert::same([], $nove->steps);
Assert::same([], $nove->inputs);

// --- vstupy jdou přes InputMapper: díry a prázdné řádky ---

$sVstupy = WorkflowMapper::toWorkflow([
	'name' => 'w',
	'description' => '',
	'inputs' => [
		3 => ['name' => 'zet', 'required' => true, 'default' => '', 'description' => ''],
		0 => ['name' => 'alfa', 'required' => false, 'default' => 'x', 'description' => 'Áčko'],
		1 => ['name' => '', 'required' => true, 'default' => '', 'description' => 'nikdo'],
	],
]);

Assert::same(['alfa', 'zet'], \array_keys($sVstupy->inputs));
Assert::false($sVstupy->inputs['alfa']->required);
Assert::same('x', $sVstupy->inputs['alfa']->default);

// --- toValues dává tvar, který formulář očekává ---

$values = WorkflowMapper::toValues(new Workflow(
	name: 'plne',
	inputs: ['a' => new Input(name: 'a', description: 'Áčko')],
	steps: [new SetStep(key: 'x', value: Template::parse('1'))],
	description: 'Popis',
));

Assert::same('plne', $values['name']);
Assert::same('Popis', $values['description']);
Assert::same(
	[['name' => 'a', 'required' => true, 'default' => '', 'description' => 'Áčko']],
	$values['inputs'],
);
Assert::false(\array_key_exists('steps', $values), 'kroky do formuláře nepatří');

// Nevyplněný popis vyjde jako '', ne jako null — formulář chce řetězce.
Assert::same('', WorkflowMapper::toValues(new Workflow(name: 'holy'))['description']);
