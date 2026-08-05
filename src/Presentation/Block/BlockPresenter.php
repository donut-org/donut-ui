<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Block;

use Donut\BlockRepository;
use Donut\Gui\Presentation\Workflow\WorkflowPresenter;
use Donut\Parser\ParseException;
use Nette\Application\UI\Presenter;


final class BlockPresenter extends Presenter
{
	public function renderDefault(): void
	{
		$dir = WorkflowPresenter::projectDir() . '/blocks';

		try {
			$repository = new BlockRepository($dir);

		} catch (ParseException $e) {
			$this->template->blocks = [];
			$this->template->error = $e->getMessage();
			$this->template->dir = $dir;

			return;
		}

		$blocks = [];

		foreach ($repository->getNames() as $name) {
			$blocks[$name] = $repository->get($name);
		}

		$this->template->blocks = $blocks;
		$this->template->error = null;
		$this->template->dir = $dir;
	}
}
