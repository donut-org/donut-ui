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

// The inputs are deliberately NOT in alphabetical order: declaration order
// is filter, compact, while sorted order would be compact, filter. With an
// alphabetical fixture an implementation that sorted the slots would pass
// and nobody would notice.
//
// "compact" is required AND has a default — the validator never reports such
// an input as unfilled, so the form must not mark it required either.
FileSystem::write($project . '/blocks/jq.json', json_encode([
	'name' => 'jq', 'command' => 'jq', 'args' => [['{%filter%}'], ['{%compact%}']],
	'inputs' => [
		'filter' => ['required' => true],
		'compact' => ['required' => true, 'default' => '-c'],
	],
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

// The block is not a dropdown any more: switching it would leave the inputs
// of the old one standing, and the form has no way of telling which values
// belong to the new block. It is text with a link to the block itself.
Assert::notContains('name="block"', $html);
// The order of the query parameters is the router's, so each one is pinned
// on its own rather than in one fixed sequence.
Assert::match('~<a[^>]*href="[^"]*presenter=Block[^"]*"[^>]*>jq</a>~', $html);
Assert::match('~<a[^>]*href="[^"]*action=detail[^"]*"[^>]*>jq</a>~', $html);
Assert::match('~<a[^>]*href="[^"]*name=jq[^"]*"[^>]*>jq</a>~', $html);

// one row per declared input, in the block's order, stdin last
Assert::match('~name="in\[0\]\[value\]"[^>]*value="\.id"~', $html);
Assert::contains('>filter<', $html);
Assert::contains('>compact<', $html);
Assert::contains('>stdin<', $html);

// the name of the input never travels through the POST — it is arbitrary
// text, while a Nette component name has to match [a-zA-Z0-9_]+
Assert::notContains('name="in[0][key]"', $html);

// required exactly where the validator would complain: filter has no
// default, compact has one, stdin follows stdin.required
Assert::match('~name="in\[0\]\[value\]"[^>]*required~', $html);
Assert::notMatch('~name="in\[1\]\[value\]"[^>]*required~', $html);
Assert::match('~name="in\[2\]\[value\]"[^>]*required~', $html);

// the declaration is shown, not hidden: the default as a placeholder
Assert::match('~name="in\[1\]\[value\]"[^>]*placeholder="-c"~', $html);
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
Assert::contains('>jq</a>', $html, 'the block must not be lost');
Assert::match('~name="in\[0\]\[value\]"[^>]*value="\.id"~', $html, "the step's inputs must not be lost");
Assert::match('~name="out\[stdout\]"[^>]*value="id"~', $html, "the step's outputs must not be lost");

// --- saving the edit ---

[$response] = runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]', 'do' => 'stepForm-submit'],
	[
		'type' => 'run', 'name' => 'named',
		// Index 1 is "compact" and stays empty: an empty slot is not written
		// to `in` at all, or it would suppress the block's default. Index 2
		// is stdin — the server knows that from the block, the POST does not
		// say it anywhere.
		'in' => [0 => ['value' => '.title'], 1 => ['value' => ''], 2 => ['value' => '{%x%}']],
		'out' => ['stdout' => 'title', 'stderr' => '', 'exit_code' => ''],
		'timeout' => '', 'allowFailure' => 'inherit', 'allowFailureCodes' => '',
		'save' => 'Save',
	],
);

Assert::type(RedirectResponse::class, $response);

$run = $steps()[0];
Assert::type(RunStep::class, $run);
Assert::same('named', $run->name);
// The POST carries no block at all any more — the server takes it from the
// step it is editing. Without this the step would be saved with an empty
// block.
Assert::same('jq', $run->block, 'the block survives a save that never mentions it');
Assert::same(['filter', 'stdin'], array_keys($run->in), 'the empty slot is not written');
Assert::same('.title', $run->in['filter']->getSource());
Assert::same('{%x%}', $run->in['stdin']->getSource(), 'index 2 is stdin, by position');
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
// The step reads {%nope%}, a key nothing in the workflow ever writes — a
// hard error for the validator. It must still get saved: a workflow being
// built is invalid most of the time, and a GUI that refused to save it
// would be unusable.
//
// Note: this deliberately isn't manufactured by leaving a required input
// empty any more. Required inputs now carry setRequired(), so the form
// itself stops that — which is the point of Task 4, not a property of
// saving.

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]', 'do' => 'stepForm-submit'],
	[
		'type' => 'run', 'name' => '',
		'in' => [0 => ['value' => '{%nope%}'], 1 => ['value' => ''], 2 => ['value' => 'x']],
		'out' => [], 'timeout' => '',
		'allowFailure' => 'inherit', 'allowFailureCodes' => '', 'save' => 'Save',
	],
);

Assert::same('{%nope%}', $steps()[0]->in['filter']->getSource(), 'an invalid step still gets saved');

// --- a new run step needs to know its block ---
//
// The whole input list comes from the block. Without it there is nothing to
// build the form from, and an address that leaves it out is a wrong request,
// not a missing page — the picker is the way in.

$e = Assert::exception(
	fn() => createWorkflowPresenter([], true, new Profile(\basename($project), $project))
		->run(new NetteRequest('Workflow', 'GET', [
			'action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]', 'type' => 'run',
		])),
	BadRequestException::class,
);
Assert::same(400, $e->getHttpCode());

// --- a block that is not in blocks/ is a 404, not a half-usable form ---

$e = Assert::exception(
	fn() => createWorkflowPresenter([], true, new Profile(\basename($project), $project))
		->run(new NetteRequest('Workflow', 'GET', [
			'action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]',
			'type' => 'run', 'block' => 'nope',
		])),
	BadRequestException::class,
);
Assert::same(404, $e->getHttpCode());

// --- editing takes the block from the step, not from the address ---
//
// A forged `block` in the query string must not decide which inputs the form
// offers; the step already says which block it calls.

[, $html] = runWorkflowPresenterIn($project, [
	'action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]', 'block' => 'nope',
]);

Assert::contains('<form', $html, 'the step is edited, the address is ignored');

// --- a key the block does not declare is shown, and must be cleared ---
//
// The block used to declare it, or it is a typo. Either way the form must
// not drop it silently: it renders as a slot with a rule that the field has
// to be empty, so saving is possible only once the user has seen it and
// cleared it themselves.

FileSystem::write($project . '/workflows/leftover.json', json_encode([
	'name' => 'leftover',
	'steps' => [[
		'type' => 'run', 'block' => 'jq',
		'in' => ['filter' => '.id', 'filtr' => '.old'],
	]],
]));

[, $html] = runWorkflowPresenterIn($project, [
	'action' => 'step', 'name' => 'leftover', 'at' => 'leftover.json:steps[0]',
]);

Assert::contains('>filtr<', $html, 'the undeclared key is visible');
Assert::contains('does not declare this input', $html);
Assert::match('~name="in\[3\]\[value\]"[^>]*value="\.old"~', $html, 'undeclared keys go last');

// saving with the field still filled in is refused
$leftover = fn(): array => (new WorkflowParser)
	->parseFile($project . '/workflows/leftover.json')->steps;

runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'leftover', 'at' => 'leftover.json:steps[0]', 'do' => 'stepForm-submit'],
	[
		'type' => 'run', 'name' => '',
		'in' => [0 => ['value' => '.id'], 1 => ['value' => ''], 2 => ['value' => 'x'], 3 => ['value' => '.old']],
		'out' => [], 'timeout' => '',
		'allowFailure' => 'inherit', 'allowFailureCodes' => '', 'save' => 'Save',
	],
);

Assert::same(
	['filter', 'filtr'],
	array_keys($leftover()[0]->in),
	'a filled-in undeclared field must not save'
);

// cleared, it saves and the key is gone
runWorkflowPresenterIn(
	$project,
	['action' => 'step', 'name' => 'leftover', 'at' => 'leftover.json:steps[0]', 'do' => 'stepForm-submit'],
	[
		'type' => 'run', 'name' => '',
		'in' => [0 => ['value' => '.id'], 1 => ['value' => ''], 2 => ['value' => 'x'], 3 => ['value' => '']],
		'out' => [], 'timeout' => '',
		'allowFailure' => 'inherit', 'allowFailureCodes' => '', 'save' => 'Save',
	],
);

Assert::same(['filter', 'stdin'], array_keys($leftover()[0]->in), 'cleared, the key is gone');

// --- a new run step keeps the block it was picked with ---
//
// The other source of the block: not the step being edited, but the `block`
// query parameter the Task 2 picker sends. This is the flow that creates
// every run step, and the block travels only in the form's action URL — the
// POST body never mentions it. Its own workflow, so the indexes of w.json
// stay put.

FileSystem::write($project . '/workflows/fresh.json', json_encode([
	'name' => 'fresh', 'steps' => [],
]));

runWorkflowPresenterIn(
	$project,
	[
		'action' => 'step', 'name' => 'fresh', 'at' => 'fresh.json:steps[0]',
		'type' => 'run', 'block' => 'jq', 'do' => 'stepForm-submit',
	],
	[
		'type' => 'run', 'name' => '',
		'in' => [0 => ['value' => '.x'], 1 => ['value' => ''], 2 => ['value' => 'y']],
		'out' => [], 'timeout' => '',
		'allowFailure' => 'inherit', 'allowFailureCodes' => '', 'save' => 'Save',
	],
);

$fresh = (new WorkflowParser)->parseFile($project . '/workflows/fresh.json')->steps;
Assert::count(1, $fresh, 'the new step was inserted');
Assert::type(RunStep::class, $fresh[0]);
Assert::same('jq', $fresh[0]->block, 'a new step keeps the block it was picked with');
Assert::same(['filter', 'stdin'], array_keys($fresh[0]->in));
Assert::same('.x', $fresh[0]->in['filter']->getSource());
Assert::same('y', $fresh[0]->in['stdin']->getSource());

// --- a block that cannot be read: the page survives a GET, a POST is a 4xx ---
//
// actionStep()'s ParseException arm keeps the page on purpose — the message
// is the only way to see what to fix. But a POST never reaches the template:
// processSignal() resolves stepForm before rendering, so the form gets built
// with no block at all. That must end the request, not crash it.

$broken = TEMP_DIR . '/broken';
FileSystem::createDir($broken . '/blocks');
FileSystem::createDir($broken . '/workflows');
FileSystem::write($broken . '/blocks/jq.json', '{ this is not json');
FileSystem::write($broken . '/workflows/w.json', json_encode([
	'name' => 'w',
	'steps' => [['type' => 'run', 'block' => 'jq', 'in' => ['filter' => '.id']]],
]));

[, $html] = runWorkflowPresenterIn($broken, [
	'action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]',
]);

Assert::contains('alert alert-danger', $html, 'the GET keeps the page and says what is broken');
Assert::notContains('<form', $html, 'and builds no form, because there is nothing to build it from');

$brokenPost = [
	'type' => 'run', 'name' => '',
	'in' => [0 => ['value' => '.id']], 'out' => [], 'timeout' => '',
	'allowFailure' => 'inherit', 'allowFailureCodes' => '', 'save' => 'Save',
];

$e = Assert::exception(
	fn() => createWorkflowPresenter($brokenPost, true, new Profile(\basename($broken), $broken))
		->run(new NetteRequest('Workflow', 'POST', [
			'action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]',
			'do' => 'stepForm-submit',
		], $brokenPost)),
	BadRequestException::class,
);
// A 4xx, not a LogicException and not a 500. Same answer as for a block that
// isn't there at all — from the form's side the two are one case.
Assert::same(404, $e->getHttpCode());
Assert::contains('cannot be read', $e->getMessage());

// The other way the block can fail to resolve: BlockRepository reports a
// missing blocks/ directory the same way it reports a broken file, so the
// same POST must end the same way.
FileSystem::delete($broken . '/blocks');

$e = Assert::exception(
	fn() => createWorkflowPresenter($brokenPost, true, new Profile(\basename($broken), $broken))
		->run(new NetteRequest('Workflow', 'POST', [
			'action' => 'step', 'name' => 'w', 'at' => 'w.json:steps[0]',
			'do' => 'stepForm-submit',
		], $brokenPost)),
	BadRequestException::class,
);
Assert::same(404, $e->getHttpCode());

FileSystem::delete(TEMP_DIR);
