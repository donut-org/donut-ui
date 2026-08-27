<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Block;

use Donut\BlockRepository;
use Donut\Format\Block;
use Donut\Format\Workflow;
use Donut\Gui\BlockMapper;
use Donut\Gui\BlockStore;
use Donut\Gui\BlockUsage;
use Donut\Gui\FormFactory;
use Donut\Gui\Presentation\LayoutTemplate;
use Donut\Gui\ProfileDir;
use Donut\Gui\WorkflowRepository;
use Donut\Parser\NotFoundException;
use Donut\Parser\ParseException;
use Donut\Profile;
use Donut\Validator\BlockValidator;
use Donut\Validator\Problem;
use Donut\Writer\WriteException;
use Nette\Application\UI\Form;
use Nette\Application\UI\Presenter;
use Nette\IOException;


final class BlockPresenter extends Presenter
{
	private ?Block $edited = null;

	private ?Block $detail = null;


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
		/** @var BlockDefaultTemplate $template */
		$template = $this->template;

		$dir = $this->profile->blocksDir();

		try {
			$repository = new BlockRepository($dir);

		} catch (ParseException $e) {
			// The only error BlockRepository lets through here is a missing
			// directory — it fails nowhere else in the constructor, so the hint
			// always applies, and we don't need to ask is_dir() here (unlike
			// Workflow:detail). The message comes from donut (shared with the
			// CLI), the hint belongs here — in the presenter, same as for
			// Workflow:detail.
			$template->blocks = [];
			$template->error = $e->getMessage() . ' ' . ProfileDir::hint();
			$template->dir = $dir;

			return;
		}

		$blocks = [];

		foreach ($repository->getNames() as $name) {
			try {
				$blocks[$name] = $repository->get($name);

			} catch (ParseException $e) {
				// A broken file must not hide the others — same rule as
				// `donut --list`.
				$blocks[$name] = $e->getMessage();
			}
		}

		$template->blocks = $blocks;
		$template->error = null;
		$template->usage = BlockUsage::of($this->loadWorkflows());
		$template->dir = $dir;
	}


	public function actionDetail(string $name): void
	{
		try {
			$this->detail = $this->store()->get($name);

		} catch (NotFoundException $e) {
			// A URL that names something which isn't there is a 404, not a
			// page about it: a typo in the address bar must not look like a
			// resource to every client that asks. A file that exists and
			// won't parse takes the branch below and keeps its 200 — that
			// message is the only way to see what to fix.
			$this->error($e->getMessage());

		} catch (ParseException $e) {
			// A missing directory and an unparseable file end the same way:
			// the page renders with the message and a link to edit, because
			// a broken block is the one where the way to fix it is needed
			// the most. Workflow:detail has the same rule — the "edit
			// header" link in detail.latte sits outside {if $workflow !== null}.
			/** @var BlockDetailTemplate $template */
			$template = $this->template;
			$template->error = $e->getMessage();
		}
	}


	public function renderDetail(string $name): void
	{
		/** @var BlockDetailTemplate $template */
		$template = $this->template;
		$template->name = $name;
		$template->block = $this->detail;
		$template->usedBy = BlockUsage::of($this->loadWorkflows())[$name] ?? [];
	}


	public function actionEdit(?string $name = null): void
	{
		if ($name === null || $name === '') {
			return;
		}

		try {
			$this->edited = $this->store()->get($name);

		} catch (NotFoundException $e) {
			// A URL that names something which isn't there is a 404, not a
			// page about it: a typo in the address bar must not look like a
			// resource to every client that asks. A file that exists and
			// won't parse takes the branch below and keeps its 200 — that
			// message is the only way to see what to fix.
			$this->error($e->getMessage());

		} catch (ParseException $e) {
			/** @var BlockEditTemplate $template */
			$template = $this->template;
			$template->error = $e->getMessage();
		}
	}


	public function renderEdit(?string $name = null): void
	{
		/** @var BlockEditTemplate $template */
		$template = $this->template;
		$template->name = $name === '' ? null : $name;
		$template->usedBy = $template->name === null
			? []
			: (BlockUsage::of($this->loadWorkflows())[$template->name] ?? []);
	}


	protected function createComponentBlockForm(): Form
	{
		$form = FormFactory::create();
		$shape = $this->formShape();

		$name = $form->addText('name', 'Name')
			->setRequired('Name is required.')
			->addRule(Form::Pattern, 'Name may contain only letters, digits, a hyphen and an underscore.', '[A-Za-z0-9_-]+');

		$form->addText('description', 'Description');
		$form->addText('command', 'Command')->setRequired('Command is required.');

		$args = $form->addContainer('args');

		foreach ($shape['args'] as $g => $indexes) {
			$group = $args->addContainer((string) $g);

			foreach ($indexes as $a) {
				$group->addText((string) $a);
			}
		}

		$inputs = $form->addContainer('inputs');

		foreach ($shape['inputs'] as $i) {
			$row = $inputs->addContainer((string) $i);
			// aria-label instead of a caption: the table header says what
			// belongs in the column, but <th> names the cell, not the <input>
			// inside it — a screen reader would otherwise just read "textbox".
			// Literally as in WorkflowPresenter::createComponentHeaderForm();
			// a checkbox needs the attribute set this way, from the template
			// it would end up on the wrapping <label>, where it gets lost.
			$row->addText('name')->setHtmlAttribute('aria-label', 'Name');
			$row->addCheckbox('required')->setHtmlAttribute('aria-label', 'Required');
			$row->addText('default')->setHtmlAttribute('aria-label', 'Default');
			$row->addText('description')->setHtmlAttribute('aria-label', 'Description');
		}

		// One field, three options — the three states the file has. Two
		// checkboxes could also say "does not read stdin, but stdin is
		// required", which no block file can mean: the required flag lives
		// inside the stdin object, so without the object there is nothing to
		// be required. That combination was accepted by the form and thrown
		// away on save.
		$form->addSelect('stdin', 'Stdin', [
			'no' => 'does not read stdin',
			'optional' => 'reads stdin, optional',
			'required' => 'requires stdin',
		])
			// A description for a channel the block does not read is not
			// wrong, it is meaningless — so it is hidden rather than
			// disabled. netteForms.js does the hiding; @layout.latte loads it.
			->addCondition(Form::NotEqual, 'no')
				->toggle('stdin-description');

		$form->addText('stdinDescription', 'Stdin description');

		$form->addText('timeout', 'Timeout (s)')
			->addCondition(Form::Filled)
			->addRule(Form::Integer, 'Timeout must be an integer.')
			->addRule(Form::Min, 'Timeout must be positive.', 1);

		$form->addRadioList('allowFailure', 'Allowed failure', [
			'none' => 'exit 0 only',
			'any' => 'any exit code',
			'list' => 'only these codes:',
		])->setDefaultValue('none');

		$form->addText('allowFailureCodes')
			->addCondition(Form::Filled)
			->addRule(Form::Pattern, 'Enter codes as numbers separated by commas, for example 0, 1.', '[0-9]+(\s*,\s*[0-9]+)*');

		$form->addSubmit('save', 'Save');
		$form->onSuccess[] = $this->blockFormSucceeded(...);

		if ($this->edited !== null) {
			// Renaming is out of scope — spec: "Name is only in the form when
			// creating." The field stays visible for context, but it's
			// non-editable and its value always comes from the loaded block, not
			// from the POST — setDisabled() also protects against a manually
			// sent request with a different name (otherwise this channel could
			// overwrite someone else's block). Call order matters: setDisabled()
			// calls setValue(null) internally, so setDefaultValue() must come
			// after it. setOmitted(false) is needed too — without it, a
			// non-editable field is silently omitted from getValues() (Nette's
			// default for disabled controls) and BlockMapper would get the
			// name '' instead of the real one.
			$name->setDisabled()->setDefaultValue($this->edited->name)->setOmitted(false);

			// Same question as in formShape(), and so the same mechanism: not
			// "is this a POST?", but "does this POST belong to this form?".
			// After a foreign signal (e.g. a failed delete) the values must be
			// taken from disk — otherwise the form redraws empty and "Save"
			// writes it that way.
			if (!$this->isFormPost('blockForm-submit')) {
				$form->setDefaults((new BlockMapper)->toValues($this->edited));
			}
		}

		return $form;
	}


	public function blockFormSucceeded(Form $form): void
	{
		/** @var array<string, mixed> $values */
		$values = $form->getValues('array');

		$block = (new BlockMapper)->toBlock($values);

		try {
			$store = $this->store();

			// Creating a block must not overwrite one that already exists —
			// writeFile() overwrites without asking, and the user would lose
			// the content without a single message. Editing can't run into
			// this — the name is non-editable during it (see
			// createComponentBlockForm).
			if ($this->edited === null && $store->exists($block->name)) {
				$form->addError("Block \"{$block->name}\" already exists. Edit it, or choose another name.");

				return;
			}

		} catch (ParseException $e) {
			// A block whose file can't be parsed still counts as existing —
			// the overwrite guard must not wave it through just because
			// reading it failed.
			$form->addError($e->getMessage());

			return;
		}

		$result = (new BlockValidator)->validate($block);

		/** @var BlockEditTemplate $template */
		$template = $this->template;
		$template->errors = self::messages($result->getErrors());
		$template->warnings = self::messages($result->getWarnings());

		// An error blocks, a warning doesn't.
		if ($result->hasErrors()) {
			$form->addError('The block was not saved — fix the errors below.');

			return;
		}

		try {
			$store->save($block);

		// IOException as well as WriteException: save() creates the blocks
		// directory, and FileSystem::createDir() reports its own failure —
		// a profile with a file where blocks/ should be must end in a
		// message, not a 500.
		} catch (WriteException | IOException $e) {
			$form->addError($e->getMessage());

			return;
		}

		// The redirect is silent on its own — this is the only confirmation
		// that the write happened, so it names what was written.
		$this->flashMessage("Block \"{$block->name}\" saved.", 'success');
		$this->redirect('edit', ['name' => $block->name]);
	}


	/**
	 * @param  array<int, Problem> $problems
	 * @return array<int, string>
	 */
	private static function messages(array $problems): array
	{
		return \array_map(fn(Problem $problem): string => $problem->message, $problems);
	}


	protected function createComponentDeleteForm(): Form
	{
		$form = FormFactory::create();
		$form->addHidden('name');
		$form->addSubmit('delete', 'Delete')
			->getControlPrototype()->setAttribute('class', 'btn btn-danger');
		$form->onSuccess[] = $this->deleteFormSucceeded(...);

		return $form;
	}


	public function deleteFormSucceeded(Form $form): void
	{
		/** @var array{name: string} $values */
		$values = $form->getValues('array');

		// basename() same as in deleteWorkflowFormSucceeded(): the name comes
		// from the request. It can't reach outside on its own — BlockStore::exists()
		// looks it up in a map keyed by basename($path, '.json'), so a name with
		// a slash can never be a key in it — but the protection should be
		// visible on both halves of the GUI and shouldn't depend on a distant
		// implementation.
		$name = \basename($values['name']);

		// Same guard as in deleteWorkflowFormSucceeded(): without it, an empty
		// name would be reported as 'Block "" does not exist.', which tells
		// the user nothing.
		if ($name === '') {
			$form->addError('Nothing to delete.');

			return;
		}

		$usage = BlockUsage::of($this->loadWorkflows());

		// An error blocks, same as when saving. Deleting a block that a
		// workflow references isn't a warning — it's breaking something that
		// worked. The template won't render the button in that case; this is
		// the second safeguard, for a manually sent POST.
		if (isset($usage[$name])) {
			$form->addError(
				"Block \"{$name}\" cannot be deleted — used by: " . \implode(', ', $usage[$name]) . '.'
			);

			return;
		}

		try {
			$this->store()->delete($name);

		} catch (ParseException | IOException $e) {
			$form->addError($e->getMessage());

			return;
		}

		// Named deliberately: the overview the user lands on no longer
		// mentions the block anywhere.
		$this->flashMessage("Block \"{$name}\" deleted.", 'success');
		$this->redirect('default');
	}


	/**
	 * How many rows the form has.
	 *
	 * On a POST it's derived from the incoming data — JS never renumbers rows,
	 * so indexes can have gaps and containers must be created for exactly the
	 * keys that arrived. On a GET they're taken from the loaded block, plus
	 * one extra empty row so there's somewhere to write.
	 *
	 * Keys from the POST are filtered to digits: a Nette component name must
	 * match [a-zA-Z0-9_]+ and nothing else belongs here anyway.
	 *
	 * @return array{args: array<int, array<int, int>>, inputs: array<int, int>}
	 */
	private function formShape(): array
	{
		// A different signal (e.g. deleteForm-submit) carries no args/inputs
		// at all — without this condition, blockForm would be built with
		// zero groups and zero rows, and after a rejected delete the page
		// would show an empty block that in fact still exists.
		$post = $this->isFormPost('blockForm-submit')
			? $this->getHttpRequest()->getPost()
			: [];

		// getPost() without an argument returns an array, but its return type
		// is mixed — is_array() narrows the type here for PHPStan.
		if (\is_array($post) && $post !== []) {
			return [
				'args' => self::nestedIndexes($post['args'] ?? []),
				'inputs' => self::indexes($post['inputs'] ?? []),
			];
		}

		$args = [];
		$inputs = [];

		if ($this->edited !== null) {
			foreach (\array_values($this->edited->args) as $g => $group) {
				$args[$g] = \array_keys(\array_values($group));
			}

			$inputs = \array_keys(\array_values($this->edited->inputs));
		}

		// One extra empty row so the user has somewhere to write even
		// without JS.
		$args[] = [0];
		$inputs[] = \count($inputs);

		return ['args' => $args, 'inputs' => $inputs];
	}


	/**
	 * Does the incoming POST belong to the form of the given signal? The edit
	 * page has two forms, and the other one's (deleteForm) data says nothing
	 * about the block's content — one answer for both the form's shape and
	 * its default values.
	 *
	 * We have to ask about the HTTP method: the signal `do=blockForm-submit`
	 * can also be in the address on a GET (a hand-built address, a bookmark,
	 * history), and there getPost() returns an empty array. Without this
	 * condition the form would render empty and "Save" would write the block
	 * that way.
	 *
	 * The shape is deliberately the same as WorkflowPresenter::isFormPost() —
	 * one idiom for one question on both halves of the GUI.
	 */
	private function isFormPost(string $signal): bool
	{
		return $this->getRequest()->isMethod('POST')
			&& $this->getParameter('do') === $signal;
	}


	/**
	 * @param  mixed $raw
	 * @return array<int, int>
	 */
	private static function indexes(mixed $raw): array
	{
		if (!\is_array($raw)) {
			return [];
		}

		$keys = [];

		foreach (\array_keys($raw) as $key) {
			if (\ctype_digit((string) $key)) {
				$keys[] = (int) $key;
			}
		}

		\sort($keys);

		return $keys;
	}


	/**
	 * @param  mixed $raw
	 * @return array<int, array<int, int>>
	 */
	private static function nestedIndexes(mixed $raw): array
	{
		if (!\is_array($raw)) {
			return [];
		}

		$shape = [];

		foreach (self::indexes($raw) as $key) {
			$shape[$key] = self::indexes($raw[$key]);
		}

		return $shape;
	}


	private function store(): BlockStore
	{
		return new BlockStore($this->profile->blocksDir());
	}


	/** @return array<string, Workflow> */
	private function loadWorkflows(): array
	{
		$workflows = [];

		try {
			$repository = new WorkflowRepository($this->profile->workflowsDir());

			foreach ($repository->loadAll() as $name => $workflow) {
				// A broken workflow must not crash the page — it won't say
				// anything about block usage, but the rest has to work.
				if (!\is_string($workflow)) {
					$workflows[$name] = $workflow;
				}
			}

		} catch (ParseException) {
			// Without the workflows directory, usage simply won't show.
		}

		return $workflows;
	}
}
