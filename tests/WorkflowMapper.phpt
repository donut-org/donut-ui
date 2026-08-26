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

// --- round-trip over the reference workload, on the header and inputs ---
//
// The form doesn't edit steps, so they aren't taken into the comparison —
// toWorkflow() gets them from the original workflow.

$files = \glob(__DIR__ . '/../../docs/workflows/donut/workflows/*.json');
Assert::count(4, $files === false ? [] : $files);

foreach ($files === false ? [] : $files as $file) {
	$original = (new WorkflowParser)->parseFile($file);
	$again = WorkflowMapper::toWorkflow(WorkflowMapper::toValues($original), $original);

	Assert::same($original->name, $again->name, \basename($file));
	Assert::same($original->description, $again->description, \basename($file));
	Assert::same(\serialize($original->inputs), \serialize($again->inputs), \basename($file));
}

// --- steps are taken from the original workflow, not from the values ---
//
// This is the trap: without $original, editing the header would delete the whole tree.

$withSteps = new Workflow(
	name: 'w',
	steps: [new SetStep(key: 'a', value: Template::parse('1'))],
	description: 'Original description',
);

$afterEdit = WorkflowMapper::toWorkflow(
	['name' => 'w', 'description' => 'New description', 'inputs' => []],
	$withSteps,
);

Assert::same('New description', $afterEdit->description);
Assert::count(1, $afterEdit->steps, 'steps must not be lost when editing the header');
Assert::same('a', $afterEdit->steps[0]->key);

// --- without an original workflow (creating), an empty one is built ---

$new = WorkflowMapper::toWorkflow(['name' => 'new', 'description' => '', 'inputs' => []]);

Assert::same('new', $new->name);
Assert::null($new->description);
Assert::same([], $new->steps);
Assert::same([], $new->inputs);

// --- inputs go through InputMapper: holes and empty rows ---

$withInputs = WorkflowMapper::toWorkflow([
	'name' => 'w',
	'description' => '',
	'inputs' => [
		3 => ['name' => 'zulu', 'required' => true, 'default' => '', 'description' => ''],
		0 => ['name' => 'alpha', 'required' => false, 'default' => 'x', 'description' => 'A note'],
		1 => ['name' => '', 'required' => true, 'default' => '', 'description' => 'nobody'],
	],
]);

Assert::same(['alpha', 'zulu'], \array_keys($withInputs->inputs));
Assert::false($withInputs->inputs['alpha']->required);
Assert::same('x', $withInputs->inputs['alpha']->default);

// --- toValues gives the shape the form expects ---

$values = WorkflowMapper::toValues(new Workflow(
	name: 'full',
	inputs: ['a' => new Input(name: 'a', description: 'A note')],
	steps: [new SetStep(key: 'x', value: Template::parse('1'))],
	description: 'Description',
));

Assert::same('full', $values['name']);
Assert::same('Description', $values['description']);
Assert::same(
	[['name' => 'a', 'required' => true, 'default' => '', 'description' => 'A note']],
	$values['inputs'],
);
Assert::false(\array_key_exists('steps', $values), 'steps don\'t belong in the form');

// An unfilled description comes out as '', not as null — the form wants strings.
Assert::same('', WorkflowMapper::toValues(new Workflow(name: 'bare'))['description']);
