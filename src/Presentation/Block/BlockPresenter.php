<?php

declare(strict_types=1);

namespace Donut\Gui\Presentation\Block;

use Donut\BlockRepository;
use Donut\Gui\WorkflowRepository;
use Donut\Parser\ParseException;
use Nette\Application\UI\Presenter;


final class BlockPresenter extends Presenter
{
	public function renderDefault(): void
	{
		/** @var BlockDefaultTemplate $template */
		$template = $this->template;

		$dir = WorkflowRepository::projectDir() . '/blocks';

		try {
			$repository = new BlockRepository($dir);

		} catch (ParseException $e) {
			$template->blocks = [];
			$template->error = $e->getMessage();
			$template->dir = $dir;

			return;
		}

		$blocks = [];

		foreach ($repository->getNames() as $name) {
			try {
				$blocks[$name] = $repository->get($name);

			} catch (ParseException $e) {
				// Vadný soubor nesmí schovat ostatní — stejné pravidlo jako
				// u `donut --list`.
				$blocks[$name] = $e->getMessage();
			}
		}

		$template->blocks = $blocks;
		$template->error = null;
		$template->dir = $dir;
	}
}
