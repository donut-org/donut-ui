<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\BlockRepository;
use Donut\Format\Condition;
use Donut\Format\RunStep;
use Donut\Format\Step;
use Donut\Format\Workflow;
use Donut\Gui\FormFactory;
use Donut\Gui\KeyMap;
use Donut\Gui\Presentation\LayoutTemplate;
use Donut\Gui\ProblemMap;
use Donut\Gui\ProfileDir;
use Donut\Gui\RowShape;
use Donut\Gui\StepMapper;
use Donut\Gui\StepPath;
use Donut\Gui\StepTree;
use Donut\Gui\WorkflowMapper;
use Donut\Gui\WorkflowRepository;
use Donut\Gui\WorkflowStore;
use Donut\Parser\NotFoundException;
use Donut\Parser\ParseException;
use Donut\Profile;
use Donut\Validator\Result;
use Donut\Validator\Validator;
use Donut\Writer\WriteException;
use Nette\Application\UI\Form;
use Nette\Application\UI\Presenter;
use Nette\Http\IResponse;
use Nette\IOException;


/**
 * Workflows are read from the profile, same as the CLI. Nothing is cached —
 * the file is read on every request.
 */
final class WorkflowPresenter extends Presenter
{
	private ?Step $editedStep = null;

	private ?StepPath $stepAt = null;

	private string $stepType = '';

	private ?Workflow $editedWorkflow = null;


	public function __construct(private readonly Profile $profile)
	{
	}


	protected function beforeRender(): void
	{
		/** @var LayoutTemplate $template */
		$template = $this->template;
		$template->profile = $this->profile->name();
	}


	public function renderDefault(): void
	{
		/** @var WorkflowDefaultTemplate $template */
		$template = $this->template;

		$dir = $this->workflowDir();

		try {
			$repository = new WorkflowRepository($dir);

		} catch (ParseException $e) {
			$template->workflows = [];
			$template->error = $e->getMessage();
			$template->dir = $dir;

			return;
		}

		$template->workflows = $repository->loadAll();
		$template->error = null;
		$template->dir = $dir;
	}


	public function renderDetail(string $name, ?string $key = null): void
	{
		/** @var WorkflowDetailTemplate $template */
		$template = $this->template;
		$template->error = null;

		// $name comes from the query string. WorkflowRepository::get() looks it
		// up as a key in the list of files that actually exist, so slashes
		// alone can't escape the directory — basename() is extra, to keep that true
		// even if the file path is ever assembled by hand again.
		$name = \basename($name);
		$template->name = $name;

		try {
			$repository = new WorkflowRepository($this->workflowDir());
			$workflow = $repository->get($name);

		} catch (NotFoundException $e) {
			// A URL that names something which isn't there is a 404, not a
			// page about it: a typo in the address bar must not look like a
			// resource to every client that asks. A file that exists and
			// won't parse takes the branch below and keeps its 200 — that
			// message is the only way to see what to fix.
			$this->error($e->getMessage());

		} catch (ParseException $e) {
			$template->error = $e->getMessage();
			return;
		}

		try {
			$blocks = new BlockRepository($this->blockDir());
			$result = (new Validator($blocks))->validate($workflow);

		} catch (ParseException $e) {
			// Missing or broken blocks aren't a reason to hide the whole detail —
			// same rule as in blockNames(). Without the validator only the
			// error itself is reported, but the header, the link to the
			// envelope and the step tree stay; otherwise a fresh project
			// without blocks/ would have a workflow that nothing more can be
			// done with.
			//
			// An empty Result doesn't mean "nothing to report" here, but
			// "didn't validate" — the exception can also come from inside
			// validation, from a broken block file, and then the real
			// findings (e.g. "block does not exist") are gone too. Without
			// this guard the page looks validated.
			$dir = $this->blockDir();

			// The `mkdir -p` hint only makes sense for a missing directory, not
			// for a broken block file. The block overview has it; the detail
			// is where a fresh user arrives first, right after creating a
			// workflow.
			$hint = \is_dir($dir) ? '' : ' ' . ProfileDir::hint();

			$template->error = 'Validation did not run: ' . $e->getMessage() . $hint;
			$result = new Result;
		}

		$problems = ProblemMap::fromResult($result);

		$template->workflow = $workflow;
		$template->problems = $problems;
		$template->workflowProblems = $problems->at(StepPath::workflow($workflow->name));

		$template->keys = KeyMap::of($workflow);

		// An empty string from the address means "nothing selected", not a
		// key named "".
		$template->selectedKey = ($key ?? '') === '' ? null : $key;
		$template->selectedKeyExists = $template->selectedKey === null
			|| \in_array($template->selectedKey, $template->keys->keys(), true);
	}


	protected function createComponentStepTree(): StepTreeControl
	{
		$raw = $this->getParameter('name');

		return new StepTreeControl(
			$this->workflowDir(),
			// basename() same as in renderDetail(): the name is from the query
			// string, and this is the only place the component gets it.
			\basename(\is_string($raw) ? $raw : ''),
		);
	}


	public function actionStep(string $name, string $at, ?string $type = null): void
	{
		/** @var WorkflowStepTemplate $template */
		$template = $this->template;

		try {
			$this->stepAt = StepPath::parse($at);

			if ($this->stepAt->workflowName() !== $name) {
				throw new \InvalidArgumentException("Path \"{$at}\" does not belong to workflow \"{$name}\".");
			}

			$repository = new WorkflowRepository($this->workflowDir());
			$workflow = $repository->get($name);

			if ($type === null || $type === '') {
				// Editing an existing step.
				$this->editedStep = StepTree::get($workflow, $this->stepAt);
				$editedType = StepMapper::toValues($this->editedStep)['type'];
				$this->stepType = \is_string($editedType) ? $editedType : '';

			} else {
				// A new step — not saved anywhere yet, just the type and target
				// position.
				$this->stepType = $type;
			}

		// Everything below arrives from the query string, so each failure is a
		// statement about the address, not about the page.
		} catch (NotFoundException | \OutOfRangeException $e) {
			// The address is well formed and names a workflow or a step that
			// isn't there.
			$this->error($e->getMessage());

		} catch (\InvalidArgumentException $e) {
			// The address itself is wrong: `at` doesn't parse, or it points
			// into a different workflow. Answering 404 here would tell the
			// caller to look elsewhere, when what they need is to fix the
			// request they sent.
			$this->error($e->getMessage(), IResponse::S400_BadRequest);

		} catch (ParseException $e) {
			// A workflow that exists and won't parse keeps its page: this is
			// the one message that says what to fix.
			$template->error = $e->getMessage();
		}
	}


	public function renderStep(string $name, string $at): void
	{
		/** @var WorkflowStepTemplate $template */
		$template = $this->template;
		$template->name = $name;
		$template->at = $at;
		// The type from the address isn't read again — actionStep() already
		// resolved it, and for editing an existing step derived it from the
		// step itself, not from the address.
		$template->type = $this->stepType;
		$template->blocks = $this->blockNames();
	}


	/**
	 * Block names for the dropdown list. A missing blocks directory isn't
	 * a reason to crash the page — the list simply stays empty.
	 *
	 * @return array<string, string>
	 */
	private function blockNames(): array
	{
		try {
			$names = (new BlockRepository($this->blockDir()))->getNames();

		} catch (ParseException) {
			return [];
		}

		return \array_combine($names, $names);
	}


	protected function createComponentStepForm(): Form
	{
		$form = FormFactory::create();
		$form->addHidden('type')->setDefaultValue($this->stepType);
		$form->addText('name', 'Step name');

		if ($this->stepType === 'run') {
			$form->addSelect('block', 'Block', $this->blockNames())
				->setRequired('Choose a block.');

			$shape = $this->rowShape();

			$in = $form->addContainer('in');

			foreach ($shape['in'] as $i) {
				$row = $in->addContainer((string) $i);
				// aria-label instead of a caption: the table header says what
				// belongs in the column, but <th> names the cell, not the
				// <input> inside it — a screen reader would otherwise just read
				// "textbox". This also matches where labels live in this GUI
				// (at addText()). A checkbox needs it set this way: {input,
				// 'aria-label' => …} puts the attribute on the wrapping
				// <label>, where it gets lost.
				$row->addText('key')->setHtmlAttribute('aria-label', 'Block input');
				$row->addText('value')->setHtmlAttribute('aria-label', 'Value');
			}

			$out = $form->addContainer('out');

			foreach ($shape['out'] as $i) {
				$row = $out->addContainer((string) $i);
				$row->addSelect('channel', null, \array_combine(RunStep::Channels, RunStep::Channels))
					->setPrompt('—')
					->setHtmlAttribute('aria-label', 'Block output');
				$row->addText('value')->setHtmlAttribute('aria-label', 'Map key');
			}

			$form->addText('timeout', 'Timeout (s)')
				->addCondition(Form::Filled)
				->addRule(Form::Integer, 'Timeout must be an integer.')
				->addRule(Form::Min, 'Timeout must be positive.', 1);

			$form->addRadioList('allowFailure', 'Allowed failure', [
				'inherit' => 'inherit from the block',
				'none' => 'exit 0 only',
				'any' => 'any exit code',
				'list' => 'only these codes:',
			])->setDefaultValue('inherit');

			$form->addText('allowFailureCodes')
				->addCondition(Form::Filled)
				->addRule(Form::Pattern, 'Enter codes as numbers separated by commas, for example 0, 1.', '[0-9]+(\s*,\s*[0-9]+)*');

		} elseif ($this->stepType === 'set') {
			$form->addText('key', 'Key')->setRequired('Key is required.');
			$form->addText('value', 'Value');

		} elseif ($this->stepType === 'if') {
			$form->addText('left', 'Left');
			$form->addSelect('op', 'Operator', \array_combine(Condition::Operators, Condition::Operators))
				->setRequired('Choose an operator.');
			$form->addText('right', 'Right');

		} elseif ($this->stepType === 'foreach') {
			$form->addText('over', 'Iterate over')->setRequired('Fill in what to iterate over.');
			$form->addText('as', 'Item name')->setRequired('Fill in the item name.');
		}

		$form->addSubmit('save', 'Save');
		$form->onSuccess[] = $this->stepFormSucceeded(...);

		// Same question as in rowShape(), and so the same mechanism: not
		// "is this a POST?", but "does this POST belong to this form?". Today
		// step.latte has only one form, so a foreign signal can't arrive here —
		// but one idiom for one question across all three forms is what keeps
		// a GET with the signal in the address out of play.
		if ($this->editedStep !== null && !$this->isFormPost('stepForm-submit')) {
			$form->setDefaults(StepMapper::toValues($this->editedStep));
		}

		return $form;
	}


	/**
	 * @return array{in: array<int, int>, out: array<int, int>}
	 */
	private function rowShape(): array
	{
		// A different signal carries no in/out at all — without this
		// condition the form would be built with zero rows and the page would
		// show an empty step that in fact isn't empty.
		$post = $this->isFormPost('stepForm-submit')
			? $this->getHttpRequest()->getPost()
			: null;

		$in = \is_array($post) ? ($post['in'] ?? null) : null;
		$out = \is_array($post) ? ($post['out'] ?? null) : null;

		$step = $this->editedStep;

		return [
			'in' => RowShape::of($in, $step instanceof RunStep ? \count($step->in) : 0),
			'out' => RowShape::of($out, $step instanceof RunStep ? \count($step->out) : 0),
		];
	}


	/**
	 * Does the incoming POST belong to the form of the given signal? The edit
	 * page has two forms, and the other one's (deleteWorkflowForm) data says
	 * nothing about the header — one answer for both the form's shape and
	 * its default values.
	 *
	 * We have to ask about the HTTP method: the signal `do=headerForm-submit`
	 * can also be in the address on a GET (a hand-built address, a bookmark,
	 * history), and there getPost() returns an empty array. Without this
	 * condition the form would render empty and "Save" would write an empty
	 * description and no inputs.
	 */
	private function isFormPost(string $signal): bool
	{
		return $this->getRequest()->isMethod('POST')
			&& $this->getParameter('do') === $signal;
	}


	public function stepFormSucceeded(Form $form): void
	{
		/** @var array<string, mixed> $values */
		$values = $form->getValues('array');
		$rawName = $this->getParameter('name');
		$name = \is_string($rawName) ? $rawName : '';

		try {
			// The server decides the type, not the hidden input from the POST —
			// the form was built according to $this->stepType and the POST
			// must match the same type; otherwise (foreach → set etc.)
			// keepChildren() below would have nothing to decide on and the
			// subtree would silently vanish.
			$step = StepMapper::toStep(['type' => $this->stepType] + $values);
			$at = $this->stepAt;

			if ($at === null) {
				throw new \InvalidArgumentException('Step path is missing.');
			}

			$repository = new WorkflowRepository($this->workflowDir());
			$workflow = $repository->get($name);

			// Editing an if or foreach must not discard their branches — the
			// form doesn't edit them, so they carry over from the original
			// step.
			if ($this->editedStep !== null) {
				$step = StepMapper::keepChildren($this->editedStep, $step);
				$workflow = StepTree::replace($workflow, $at, $step);

			} else {
				$workflow = StepTree::insert($workflow, $at, $step);
			}

			(new WorkflowStore($this->workflowDir()))->save($workflow);

		// Reading goes through JsonSource, which reports ParseException, and
		// WorkflowWriter::writeFile() wraps its own IOException in a
		// WriteException — but WorkflowStore::save() creates the workflows
		// directory first, and FileSystem::createDir() reports its failure as
		// an IOException that nothing wraps.
		} catch (\InvalidArgumentException | \OutOfRangeException | ParseException | WriteException | IOException $e) {
			$form->addError($e->getMessage());

			return;
		}

		$this->flashMessage('Step saved.', 'success');
		$this->redirect('detail', ['name' => $name]);
	}


	public function actionEdit(?string $name = null): void
	{
		if ($name === null || $name === '') {
			return;
		}

		/** @var WorkflowEditTemplate $template */
		$template = $this->template;

		try {
			$this->editedWorkflow = (new WorkflowRepository($this->workflowDir()))->get(\basename($name));

		} catch (NotFoundException $e) {
			// A URL that names something which isn't there is a 404, not a
			// page about it: a typo in the address bar must not look like a
			// resource to every client that asks. A file that exists and
			// won't parse takes the branch below and keeps its 200 — that
			// message is the only way to see what to fix.
			$this->error($e->getMessage());

		} catch (ParseException $e) {
			$template->error = $e->getMessage();
		}
	}


	public function renderEdit(?string $name = null): void
	{
		/** @var WorkflowEditTemplate $template */
		$template = $this->template;
		$template->name = ($name ?? '') === '' ? null : \basename((string) $name);
	}


	protected function createComponentHeaderForm(): Form
	{
		$form = FormFactory::create();

		$nameInput = $form->addText('name', 'Name')
			->setRequired('Name is required.')
			->addRule(Form::Pattern, 'Name may contain only letters, digits, a hyphen and an underscore.', '[A-Za-z0-9_-]+');

		if ($this->editedWorkflow !== null) {
			// The GUI can't rename — workflows are run by name from cron and
			// from the CLI. The order is binding: setDisabled() clears the
			// value, so it must precede setDefaultValue(), and without
			// setOmitted(false) the disabled field would be silently omitted
			// from getValues().
			$nameInput->setDisabled()
				->setDefaultValue($this->editedWorkflow->name)
				->setOmitted(false);
		}

		$form->addText('description', 'Description');

		$post = $this->isFormPost('headerForm-submit')
			? $this->getHttpRequest()->getPost()
			: null;

		$inputs = $form->addContainer('inputs');

		// $this->editedWorkflow?->inputs ?? [] falsely reports nullsafe.neverNull
		// to PHPStan (level max) — splitting it into a variable works around
		// that, same as in WorkflowMapper::toWorkflow().
		$existingInputs = $this->editedWorkflow?->inputs;

		foreach (RowShape::of(\is_array($post) ? ($post['inputs'] ?? null) : null, \count($existingInputs ?? [])) as $i) {
			$row = $inputs->addContainer((string) $i);
			// aria-label, see createComponentStepForm().
			$row->addText('name')->setHtmlAttribute('aria-label', 'Name');
			$row->addCheckbox('required')->setHtmlAttribute('aria-label', 'Required');
			$row->addText('default')->setHtmlAttribute('aria-label', 'Default');
			$row->addText('description')->setHtmlAttribute('aria-label', 'Description');
		}

		$form->addSubmit('save', 'Save');
		$form->onSuccess[] = $this->headerFormSucceeded(...);

		// Same question as $post above, and so the same mechanism: not "is this
		// a POST?", but "does this POST belong to this form?". After a
		// foreign signal (e.g. a failed delete), $post is null and the values
		// must be taken from disk — otherwise the form redraws empty and
		// "Save" writes an empty description and no inputs.
		if ($this->editedWorkflow !== null && $post === null) {
			$form->setDefaults(WorkflowMapper::toValues($this->editedWorkflow));
		}

		return $form;
	}


	public function headerFormSucceeded(Form $form): void
	{
		/** @var array<string, mixed> $values */
		$values = $form->getValues('array');

		$workflow = WorkflowMapper::toWorkflow($values, $this->editedWorkflow);

		try {
			$store = new WorkflowStore($this->workflowDir());

			// Creating a workflow must not overwrite one that already exists —
			// writeFile() overwrites without asking, and the user would lose
			// the content without a single message.
			if ($this->editedWorkflow === null && $store->exists($workflow->name)) {
				$form->addError("Workflow \"{$workflow->name}\" already exists. Edit it, or choose another name.");

				return;
			}

			$store->save($workflow);

		} catch (ParseException | WriteException | IOException $e) {
			// WorkflowWriter::writeFile() wraps its internal IOException in a
			// WriteException, but WorkflowStore::save() creates the workflows
			// directory before writing, and FileSystem::createDir() reports
			// its own failure — that IOException is nobody's to wrap.
			$form->addError($e->getMessage());

			return;
		}

		$this->flashMessage("Workflow \"{$workflow->name}\" saved.", 'success');
		$this->redirect('detail', ['name' => $workflow->name]);
	}


	protected function createComponentDeleteWorkflowForm(): Form
	{
		$form = FormFactory::create();
		$form->addHidden('name');
		$form->addSubmit('save', 'Delete')
			->getControlPrototype()->setAttribute('class', 'btn btn-danger');
		$form->onSuccess[] = $this->deleteWorkflowFormSucceeded(...);

		return $form;
	}


	public function deleteWorkflowFormSucceeded(Form $form): void
	{
		/** @var array{name: string} $values */
		$values = $form->getValues('array');

		// The name comes from the hidden field, not from $this->editedWorkflow —
		// deleting must not depend on the file having parsed successfully.
		// A broken workflow is exactly the one the user needs to delete the
		// most. basename() same as elsewhere: the name comes from the
		// request.
		$name = \basename($values['name']);

		if ($name === '') {
			$form->addError('Nothing to delete.');

			return;
		}

		try {
			(new WorkflowStore($this->workflowDir()))->delete($name);

		} catch (ParseException | IOException $e) {
			$form->addError($e->getMessage());

			return;
		}

		// Named deliberately: the overview the user lands on no longer
		// mentions the workflow anywhere.
		$this->flashMessage("Workflow \"{$name}\" deleted.", 'success');
		$this->redirect('default');
	}


	private function workflowDir(): string
	{
		return $this->profile->workflowsDir();
	}


	private function blockDir(): string
	{
		return $this->profile->blocksDir();
	}
}
