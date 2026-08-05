<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Workflow;

use Donut\BlockRepository;
use Donut\Format\Workflow;
use Donut\Gui\ProblemMap;
use Donut\Gui\StepPath;
use Donut\Parser\ParseException;
use Donut\Parser\WorkflowParser;
use Donut\Validator\Validator;
use Nette\Application\BadRequestException;
use Nette\Application\UI\Presenter;


/**
 * Workflow se čtou z pracovního adresáře serveru, stejně jako u CLI.
 * Nic se necachuje — soubor se čte při každém requestu.
 */
final class WorkflowPresenter extends Presenter
{
	public function renderDefault(): void
	{
		$this->template->workflows = $this->loadAll();
	}


	public function renderDetail(string $name): void
	{
		$path = self::projectDir() . '/workflows/' . $name . '.json';

		if (!\is_file($path)) {
			$this->error("Workflow \"{$name}\" neexistuje.");
		}

		$workflow = (new WorkflowParser)->parseFile($path);
		$blocks = new BlockRepository(self::projectDir() . '/blocks');
		$result = (new Validator($blocks))->validate($workflow);

		$this->template->workflow = $workflow;
		$this->template->blocks = $blocks;
		$this->template->problems = ProblemMap::fromResult($result);
		$this->template->rootPath = StepPath::root($workflow->name);
		$this->template->workflowProblems = ProblemMap::fromResult($result)
			->at($workflow->name . '.json');
	}


	public static function projectDir(): string
	{
		return \getcwd() ?: '.';
	}


	/**
	 * @return array<string, Workflow|string> jméno => workflow, nebo hláška o chybě
	 */
	private function loadAll(): array
	{
		$paths = \glob(self::projectDir() . '/workflows/*.json');
		$loaded = [];

		foreach ($paths === false ? [] : $paths as $path) {
			$name = \basename($path, '.json');

			try {
				$loaded[$name] = (new WorkflowParser)->parseFile($path);

			} catch (ParseException $e) {
				// Vadný soubor nesmí schovat ostatní — stejné pravidlo jako
				// u `donut --list`.
				$loaded[$name] = $e->getMessage();
			}
		}

		\ksort($loaded);

		return $loaded;
	}
}
