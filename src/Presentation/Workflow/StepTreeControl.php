<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\Format\Workflow;
use Donut\Gui\KeyMap;
use Donut\Gui\ProblemMap;
use Donut\Gui\StepPath;
use Donut\Gui\StepTree;
use Donut\Gui\WorkflowRepository;
use Donut\Gui\WorkflowStore;
use Donut\Parser\ParseException;
use Donut\Writer\WriteException;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Control;


/**
 * The workflow's step tree, with controls.
 *
 * As a component because it has its own template, its own signals and its
 * own state. That leaves the presenter with the list, the detail and the
 * forms.
 *
 * It gets the workflow name and directory from the constructor, not from the
 * address: a signal runs before render, so the action's parameters can't be
 * relied on, and this way the name has a single place where it's verified.
 */
final class StepTreeControl extends Control
{
	private ?string $error = null;


	public function __construct(
		private readonly string $directory,
		private readonly string $name,
	) {
	}


	public function render(
		Workflow $workflow,
		ProblemMap $problems,
		KeyMap $keys,
		?string $selected,
	): void {
		/** @var StepTreeTemplate $template */
		$template = $this->template;
		$template->setFile(__DIR__ . '/stepTree.latte');
		$template->steps = $workflow->steps;
		$template->path = StepPath::root($workflow->name);
		$template->problems = $problems;
		$template->keys = $keys;
		$template->selected = $selected;
		$template->name = $this->name;
		$template->error = $this->error;
		$template->render();
	}


	#[Requires(methods: 'POST')]
	public function handleMoveUp(): void
	{
		$this->applyToStep(fn(Workflow $w, StepPath $at): Workflow => StepTree::moveUp($w, $at));
	}


	#[Requires(methods: 'POST')]
	public function handleMoveDown(): void
	{
		$this->applyToStep(fn(Workflow $w, StepPath $at): Workflow => StepTree::moveDown($w, $at));
	}


	#[Requires(methods: 'POST')]
	public function handleDeleteStep(): void
	{
		$this->applyToStep(fn(Workflow $w, StepPath $at): Workflow => StepTree::remove($w, $at));
	}


	/**
	 * Take the path from the POST, load the workflow, perform the operation,
	 * save, go back to the overview.
	 *
	 * Validation **doesn't run** — for a workflow it doesn't block, because
	 * intermediate states of reordering are almost always invalid. Problems
	 * show up in the overview, where we return right after.
	 *
	 * On error it **doesn't redirect**: redirect() throws AbortException and
	 * the error would get nowhere. Flash messages aren't available (no
	 * session), so render is left to run through, and it renders $error.
	 *
	 * @param callable(Workflow, StepPath): Workflow $operation
	 */
	private function applyToStep(callable $operation): void
	{
		$raw = $this->getPresenter()->getHttpRequest()->getPost('at');

		try {
			$at = StepPath::parse(\is_string($raw) ? $raw : '');

			// The path carries the workflow name; if it didn't match the one
			// the component stands on, the operation would reach into a
			// foreign file.
			if ($at->workflowName() !== $this->name) {
				throw new \InvalidArgumentException(
					"Path \"{$at}\" does not belong to workflow \"{$this->name}\"."
				);
			}

			$workflow = (new WorkflowRepository($this->directory))->get($this->name);
			(new WorkflowStore($this->directory))->save($operation($workflow, $at));

		// There's nothing to throw an IOException here: reading goes through
		// JsonSource, which reports ParseException, and
		// WorkflowWriter::writeFile() wraps its own IOException in a
		// WriteException.
		} catch (\InvalidArgumentException | \OutOfRangeException | ParseException | WriteException $e) {
			$this->error = $e->getMessage();

			return;
		}

		$this->getPresenter()->redirect('detail', ['name' => $this->name]);
	}
}
