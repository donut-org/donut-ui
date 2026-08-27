<?php

declare(strict_types=1);

use Donut\Format\ForeachStep;
use Donut\Format\IfStep;
use Donut\Format\RunStep;
use Donut\Format\SetStep;
use Donut\Parser\WorkflowParser;
use Nette\Application\Responses\RedirectResponse;
use Nette\Utils\FileSystem;
use Nette\Application\BadRequestException;
use Nette\Application\Request as NetteRequest;
use Donut\Profile;
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
		['type' => 'run', 'block' => 'jq', 'in' => ['filter' => '.id'], 'out' => ['stdout' => 'id']],
		['type' => 'if', 'condition' => ['left' => '{%id%}', 'op' => 'not_empty'], 'then' => []],
	],
]));

$steps = fn(): array => (new WorkflowParser)->parseFile($project . '/workflows/w.json')->steps;

// --- editing an existing step: the form is pre-filled ---

[, $html] = runWorkflowPresenterIn($project, [
	'action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]',
]);

Assert::contains('value="jq"', $html);
Assert::contains('.id', $html);
Assert::contains('<form', $html);
// The step name label — pins WorkflowPresenter::createComponentStepForm()'s
// 'Step name' caption, since no other assertion in the suite renders it.
Assert::contains('>Step name<', $html);

// --- N5: a foreign POST must not empty the step form ---
//
// Signals in Nette are independent of the action, so a POST to `action=step`
// with `do=stepTree-deleteStep` arrives here too. When `at` in the body is
// invalid, StepTreeControl::applyToStep() deliberately doesn't redirect (the
// error would get nowhere via AbortException) and lets render run through —
// and step.latte renders stepForm. With the question "is this a POST?"
// instead of "does this POST belong to this form?", setDefaults() would be
// skipped and the form would render empty; "Save" would then write an empty
// in/out, timeout and allowFailure for a run step.

[$response, $html] = runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]', 'do' => 'stepTree-deleteStep'],
	['at' => 'nonsense'],
);

Assert::false($response instanceof RedirectResponse, 'the delete failed, the page redrew');
Assert::count(2, $steps(), 'an invalid path must not delete anything');
Assert::contains('value="jq"', $html, 'the chosen block must not be lost');
Assert::match('~name="in\[0\]\[key\]"[^>]*value="filter"~', $html, "the step's inputs must not be lost");
Assert::match('~name="in\[0\]\[value\]"[^>]*value="\.id"~', $html);
Assert::match('~name="out\[0\]\[value\]"[^>]*value="id"~', $html, "the step's outputs must not be lost");

// --- saving the edit ---

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]', 'do' => 'stepForm-submit'],
	[
		'type' => 'run', 'name' => 'named', 'block' => 'jq',
		// gap in numbering deliberately
		'in' => [0 => ['key' => 'filter', 'value' => '.title'], 2 => ['key' => 'stdin', 'value' => '{%x%}']],
		'out' => [0 => ['channel' => 'stdout', 'value' => 'title']],
		'timeout' => '', 'allowFailure' => 'inherit', 'allowFailureCodes' => '',
		'save' => 'Save',
	],
);

Assert::type(RedirectResponse::class, $response);

$run = $steps()[0];
Assert::type(RunStep::class, $run);
Assert::same('named', $run->name);
Assert::same(['filter', 'stdin'], array_keys($run->in));
Assert::same('.title', $run->in['filter']->getSource());
Assert::same(['stdout' => 'title'], $run->out);

// The rest of the workflow stayed — editing a step must not touch its neighbors.
Assert::count(2, $steps());
Assert::type(IfStep::class, $steps()[1]);

// --- a new step gets inserted at the given position ---

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[1]', 'type' => 'set', 'do' => 'stepForm-submit'],
	['type' => 'set', 'name' => '', 'key' => 'branch', 'value' => 'f/{%id%}', 'save' => 'Save'],
);

Assert::count(3, $steps());
Assert::type(SetStep::class, $steps()[1]);
Assert::same('branch', $steps()[1]->key);
Assert::type(IfStep::class, $steps()[2]);

// --- a new step into an empty branch ---

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[2].then[0]', 'type' => 'foreach', 'do' => 'stepForm-submit'],
	['type' => 'foreach', 'name' => '', 'over' => '{%list%}', 'as' => 'row', 'save' => 'Save'],
);

$if = $steps()[2];
Assert::type(IfStep::class, $if);
Assert::count(1, $if->then);
Assert::type(ForeachStep::class, $if->then[0]);
Assert::same('row', $if->then[0]->as);

// --- editing an if must not discard its branches ---

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[2]', 'do' => 'stepForm-submit'],
	['type' => 'if', 'name' => 'renamed', 'left' => '{%id%}', 'op' => 'empty', 'right' => '', 'save' => 'Save'],
);

$if = $steps()[2];
Assert::same('renamed', $if->name);
Assert::same('empty', $if->condition->op);
Assert::count(1, $if->then, 'the then branch must not be lost by editing the condition');

// --- give foreach its own subtree, so there's something to protect ---

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[2].then[0].steps[0]', 'type' => 'set', 'do' => 'stepForm-submit'],
	['type' => 'set', 'name' => '', 'key' => 'inner', 'value' => 'x', 'save' => 'Save'],
);

$foreach = $steps()[2]->then[0];
Assert::type(ForeachStep::class, $foreach);
Assert::count(1, $foreach->steps);

// --- editing foreach must not discard its subtree ---

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[2].then[0]', 'do' => 'stepForm-submit'],
	['type' => 'foreach', 'name' => 'renamed', 'over' => '{%items%}', 'as' => 'item', 'save' => 'Save'],
);

$foreach = $steps()[2]->then[0];
Assert::same('renamed', $foreach->name);
Assert::same('item', $foreach->as);
Assert::count(1, $foreach->steps, "foreach's subtree must not be lost by editing over/as");

// --- the server decides the step's type, not a hidden input from the POST ---
//
// The hidden <input name=type> is an ordinary form field — the POST can
// send it differently than how the form was built. If it were trusted,
// StepMapper::toStep() would build a SetStep with an empty key from the
// foreach form's values (which have no "key" field), and the whole foreach
// subtree would vanish. So the server ignores the type from the POST and
// uses the one it built the form with ($this->stepType, derived from the
// step being edited).

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[2].then[0]', 'do' => 'stepForm-submit'],
	['type' => 'set', 'name' => '', 'over' => '{%items%}', 'as' => 'item', 'save' => 'Save'],
);

$foreach = $steps()[2]->then[0];
Assert::type(ForeachStep::class, $foreach, "a forged type in the POST must not change the step's type");
Assert::count(1, $foreach->steps, 'a forged type must not delete the subtree');

// --- a step that isn't there is a 404, it doesn't crash and isn't a page ---
//
// It used to render the step form with the message in it, so a stale link
// or a hand-edited address looked like a step that exists.

$e = Assert::exception(
	fn() => createWorkflowPresenter([], true, new Profile(\basename($project), $project))
		->run(new NetteRequest('Workflow', 'GET', [
			'action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[99]',
		])),
	BadRequestException::class,
);
Assert::same(404, $e->getHttpCode());
Assert::contains('does not exist', $e->getMessage());

// --- an invalid workflow still gets saved: validation doesn't block ---
//
// The jq block requires both the filter and stdin inputs; a step that fills
// in neither is an error for the validator. It must still get saved.
//
// Note: the invalidity is deliberately not manufactured with a nonexistent
// block name — the `block` field is an addSelect over the list of blocks,
// and Nette rejects a value outside the list before saving is even reached.
// That would test the form's behavior, not that workflow validation doesn't
// block.

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]', 'do' => 'stepForm-submit'],
	[
		'type' => 'run', 'name' => '', 'block' => 'jq',
		'in' => [], 'out' => [], 'timeout' => '',
		'allowFailure' => 'inherit', 'allowFailureCodes' => '', 'save' => 'Save',
	],
);

Assert::same([], $steps()[0]->in, 'a step without its required inputs still gets saved');

FileSystem::delete(TEMP_DIR);
