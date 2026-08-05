<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\BlockRepository;
use Donut\Gui\ProblemMap;
use Donut\Gui\StepPath;
use Donut\Gui\WorkflowRepository;
use Donut\Parser\ParseException;
use Donut\Validator\Validator;
use Nette\Application\UI\Presenter;


/**
 * Workflow se čtou z pracovního adresáře serveru, stejně jako u CLI.
 * Nic se necachuje — soubor se čte při každém requestu.
 */
final class WorkflowPresenter extends Presenter
{
	public function renderDefault(): void
	{
		$dir = WorkflowRepository::projectDir() . '/workflows';

		try {
			$repository = new WorkflowRepository($dir);

		} catch (ParseException $e) {
			$this->template->workflows = [];
			$this->template->error = $e->getMessage();
			$this->template->dir = $dir;

			return;
		}

		$this->template->workflows = $repository->loadAll();
		$this->template->error = null;
		$this->template->dir = $dir;
	}


	public function renderDetail(string $name): void
	{
		$this->template->error = null;

		try {
			$repository = new WorkflowRepository(WorkflowRepository::projectDir() . '/workflows');
			$workflow = $repository->get($name);

		} catch (ParseException $e) {
			$this->template->error = $e->getMessage();
			return;
		}

		try {
			$blocks = new BlockRepository(WorkflowRepository::projectDir() . '/blocks');
			$result = (new Validator($blocks))->validate($workflow);

		} catch (ParseException $e) {
			$this->template->error = $e->getMessage();
			return;
		}

		$problems = ProblemMap::fromResult($result);

		$this->template->workflow = $workflow;
		$this->template->blocks = $blocks;
		$this->template->problems = $problems;
		$this->template->rootPath = StepPath::root($workflow->name);
		$this->template->workflowProblems = $problems->at(StepPath::workflow($workflow->name));
	}
}
